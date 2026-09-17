<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Doctrine\DBAL\Connection;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\Event\ConversationCreatedEvent;
use HeimrichHannot\SimpleMemberChatBundle\Event\MessageSentEvent;
use HeimrichHannot\SimpleMemberChatBundle\Event\MessagesReadEvent;
use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Exception\ChatException;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatEventDispatcher;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatTransaction;
use HeimrichHannot\SimpleMemberChatBundle\Service\ContactPermissionInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationService;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use HeimrichHannot\SimpleMemberChatBundle\Service\MessageService;
use HeimrichHannot\SimpleMemberChatBundle\Service\MessageTextSanitizer;
use HeimrichHannot\SimpleMemberChatBundle\Service\ReadTracker;
use HeimrichHannot\SimpleMemberChatBundle\Tests\ServiceTestCase;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ServicesTest extends ServiceTestCase
{
    private ConversationGatewayInterface&Stub $conversations;

    private ParticipantGatewayInterface&Stub $participants;

    private MessageGatewayInterface&Stub $messages;

    private Connection&Stub $connection;

    private EventDispatcher $dispatcher;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conversations = self::createStub(ConversationGatewayInterface::class);
        $this->participants = self::createStub(ParticipantGatewayInterface::class);
        $this->messages = self::createStub(MessageGatewayInterface::class);
        $this->connection = self::createStub(Connection::class);
        $this->connection->method('isTransactionActive')->willReturn(false);
        $this->connection->method('transactional')->willReturnCallback(function (callable $operation): mixed {
            $result = $operation();
            $this->committed = true;

            return $result;
        });
        $this->dispatcher = new EventDispatcher();
    }

    public function testOrdersPairAndDispatchesAfterCreationCommit(): void
    {
        $this->conversations = $this->createMock(ConversationGatewayInterface::class);
        $this->participants = $this->createMock(ParticipantGatewayInterface::class);
        $permission = $this->createMock(ContactPermissionInterface::class);
        $permission->expects(self::once())->method('canContact')->with(9, 7)->willReturn(true);
        $this->conversations->expects(self::once())->method('findByPair')->with(7, 9)->willReturn(null);
        $this->conversations->expects(self::once())->method('insert')->willReturnCallback(static function (string $uuid, int $low, int $high, int $now): Conversation {
            self::assertSame('7', $uuid[14]);
            self::assertSame([7, 9], [$low, $high]);

            return new Conversation(1, $uuid, $low, $high, $now, 0, 0);
        });
        $this->participants->expects(self::exactly(2))->method('add');
        $this->dispatcher->addListener(ConversationCreatedEvent::class, function (ConversationCreatedEvent $event): void {
            self::assertTrue($this->committed);
            self::assertSame(9, $event->initiatorId);
        });
        self::assertSame(7, $this->conversationService($permission)->openWith(9, 7)->memberLow);
    }

    public function testExistingConversationBypassesContactPermission(): void
    {
        $this->conversations = $this->createMock(ConversationGatewayInterface::class);
        $permission = $this->createMock(ContactPermissionInterface::class);
        $permission->expects(self::never())->method('canContact');
        $this->conversations->method('findByPair')->willReturn($this->conversation());
        $this->conversations->expects(self::never())->method('insert');
        $limiter = $this->limiter(1);
        self::assertSame(1, $this->conversationService($permission, $limiter)->openWith(9, 7)->id);
        self::assertTrue($limiter->create('9')->consume()->isAccepted());
        self::assertSame(1, $this->conversationService($permission, $limiter)->openWith(9, 7)->id);
    }

    public function testDeniedContactDoesNotWrite(): void
    {
        $this->conversations = $this->createMock(ConversationGatewayInterface::class);
        $permission = self::createStub(ContactPermissionInterface::class);
        $permission->method('canContact')->willReturn(false);
        $this->conversations->expects(self::never())->method('insert');
        $this->expectException(ChatException::class);
        $this->conversationService($permission)->openWith(9, 7);
    }

    public function testCannotOpenWithSelf(): void
    {
        $permission = $this->createMock(ContactPermissionInterface::class);
        $permission->expects(self::never())->method('canContact');
        $this->expectException(ChatException::class);
        $this->conversationService($permission)->openWith(9, 9);
    }

    public function testSendUsesLockedConversationAndDispatchesCommittedPayload(): void
    {
        $this->conversations = $this->createMock(ConversationGatewayInterface::class);
        $this->messages = $this->createMock(MessageGatewayInterface::class);
        $conversation = $this->conversation();
        $message = new Message(3, 1, 9, 'Hello', 100);
        $this->conversations->expects(self::once())->method('find')->with(1, true)->willReturn($conversation);
        $this->participants->method('memberIds')->willReturn([7, 9]);
        $this->messages->expects(self::once())->method('insert')->with(1, 9, 'Hello', self::anything())->willReturn($message);
        $this->conversations->expects(self::once())->method('updateLastMessage')->with($conversation, $message)->willReturn(new Conversation(1, $conversation->uuid, 7, 9, 0, 100, 3));
        $seen = false;
        $this->dispatcher->addListener(MessageSentEvent::class, function (MessageSentEvent $event) use (&$seen): void {
            self::assertTrue($this->committed);
            self::assertSame([7], $event->recipientIds);
            self::assertSame(3, $event->conversation->lastMessageId);
            $seen = true;
        });
        self::assertSame($message, $this->messageService()->send(1, 9, ' Hello '));
        self::assertTrue($seen);
    }

    public function testOrphanConversationCannotReceiveMessages(): void
    {
        $this->messages = $this->createMock(MessageGatewayInterface::class);
        $this->conversations->method('find')->willReturn($this->conversation());
        $this->participants->method('memberIds')->willReturn([9]);
        $this->messages->expects(self::never())->method('insert');
        $this->expectException(ChatException::class);
        $this->messageService()->send(1, 9, 'Hello');
    }

    public function testCannotSpoofAuthor(): void
    {
        $this->messages = $this->createMock(MessageGatewayInterface::class);
        $this->messages->expects(self::never())->method('insert');
        $this->expectException(ChatException::class);
        $this->messageService()->send(1, 7, 'Hello');
    }

    public function testRateLimitPreventsWrite(): void
    {
        $this->messages = $this->createMock(MessageGatewayInterface::class);
        $limiter = $this->limiter(1);
        $limiter->create('9')->consume();
        $this->messages->expects(self::never())->method('insert');
        try {
            $this->messageService($limiter)->send(1, 9, 'Hello');
            self::fail('Expected rate limit.');
        } catch (ChatException $chatException) {
            self::assertSame(429, $chatException->statusCode);
        }
    }

    public function testReadEventOnlyForChangedPosition(): void
    {
        $this->participants = $this->createMock(ParticipantGatewayInterface::class);
        $this->conversations->method('find')->willReturn($this->conversation());
        $this->participants->method('state')->willReturn([
            'lastReadAt' => 0,
            'lastReadMessageId' => 0,
            'lastPageId' => 0,
            'muted' => false,
        ]);
        $this->messages->method('find')->willReturn(new Message(3, 1, 7, 'Hello', 100));
        $this->participants->expects(self::exactly(2))->method('markRead')->willReturnOnConsecutiveCalls(true, false);
        $count = 0;
        $this->dispatcher->addListener(MessagesReadEvent::class, function () use (&$count): void {
            self::assertTrue($this->committed);
            ++$count;
        });
        $tracker = new ReadTracker($this->conversations, $this->participants, $this->messages, $this->memberProvider(9), new ChatTransaction($this->connection), new ChatEventDispatcher($this->dispatcher, new NullLogger()));
        $tracker->markRead(1, 9, 3, 12);
        $tracker->markRead(1, 9, 3, 12);
        self::assertSame(1, $count);
    }

    public function testListenerFailureIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $this->dispatcher->addListener(MessagesReadEvent::class, static function (): never {
            throw new \RuntimeException('Listener failed');
        });
        new ChatEventDispatcher($this->dispatcher, $logger)->dispatch(new MessagesReadEvent(1, 9, 3));
    }

    public function testUnauthenticatedAccessIsRejected(): void
    {
        $this->expectException(AuthenticationRequiredException::class);
        new FrontendMemberProvider(new TokenStorage())->requireMemberId();
    }

    private function conversation(): Conversation
    {
        return new Conversation(1, '01994daa-1111-7111-8111-111111111111', 7, 9, 0, 0, 0);
    }

    private function limiter(int $limit = 30): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => 'test',
            'policy' => 'sliding_window',
            'limit' => $limit,
            'interval' => '1 minute',
        ], new InMemoryStorage());
    }

    private function conversationService(ContactPermissionInterface $permission, ?RateLimiterFactory $limiter = null): ConversationService
    {
        return new ConversationService($this->conversations, $this->participants, $permission, $this->memberProvider(9), $limiter ?? $this->limiter(), new ChatTransaction($this->connection), new ChatEventDispatcher($this->dispatcher, new NullLogger()));
    }

    private function messageService(?RateLimiterFactory $limiter = null): MessageService
    {
        $authorization = self::createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);

        return new MessageService($this->conversations, $this->participants, $this->messages, new MessageTextSanitizer(new ChatOptions()), $this->memberProvider(9), $authorization, $limiter ?? $this->limiter(), new ChatTransaction($this->connection), new ChatEventDispatcher($this->dispatcher, new NullLogger()));
    }
}
