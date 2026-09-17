<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Integration;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Event\ConversationCreatedEvent;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGateway;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatEventDispatcher;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatPageUrlGenerator;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatReader;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatTransaction;
use HeimrichHannot\SimpleMemberChatBundle\Service\ContactPermissionInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationService;
use HeimrichHannot\SimpleMemberChatBundle\Service\MemberDataEraser;
use HeimrichHannot\SimpleMemberChatBundle\Service\MuteService;
use HeimrichHannot\SimpleMemberChatBundle\Service\ReadTracker;
use HeimrichHannot\SimpleMemberChatBundle\Tests\DatabaseTestCase;
use HeimrichHannot\SimpleMemberChatBundle\View\ChatViewFactory;
use HeimrichHannot\SimpleMemberChatBundle\View\DaySeparatorFactory;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GatewaysTest extends DatabaseTestCase
{
    private ConversationGateway $conversations;

    private ParticipantGateway $participants;

    private MessageGateway $messages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conversations = new ConversationGateway($this->connection);
        $this->participants = new ParticipantGateway($this->connection);
        $this->messages = new MessageGateway($this->connection);
    }

    public function testUuidRoundTripAndInvalidLookup(): void
    {
        $conversation = $this->createConversation();
        self::assertEquals($conversation, $this->conversations->findByUuid($conversation->uuid));
        self::assertNull($this->conversations->findByUuid('invalid'));
        self::assertNull($this->conversations->findByUuid(Uuid::v7()->toRfc4122()));
    }

    public function testDatabaseRejectsDuplicatePairs(): void
    {
        $this->createConversation();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->conversations->insert(Uuid::v7()->toRfc4122(), 7, 9, 100);
    }

    public function testDatabaseRejectsDuplicateUuid(): void
    {
        $conversation = $this->createConversation();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->conversations->insert($conversation->uuid, 3, 5, 100);
    }

    public function testDatabaseRejectsDuplicateParticipant(): void
    {
        $conversation = $this->createConversation();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->participants->add($conversation->id, 7, 100);
    }

    public function testCompetingCreationReturnsWinnerWithoutEvent(): void
    {
        $permission = $this->createMock(ContactPermissionInterface::class);
        $permission->expects(self::once())->method('canContact')->willReturnCallback(function (): bool {
            // Another connection commits after the service lookup but before its insert.
            $other = DriverManager::getConnection($this->connection->getParams());
            $other->transactional(static function () use ($other): void {
                $conversation = new ConversationGateway($other)->insert(Uuid::v7()->toRfc4122(), 7, 9, 100);
                $participants = new ParticipantGateway($other);
                $participants->add($conversation->id, 7, 100);
                $participants->add($conversation->id, 9, 100);
            });
            $other->close();

            return true;
        });
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConversationCreatedEvent::class, static function (): never {
            self::fail('The losing request must not dispatch a creation event.');
        });
        $service = new ConversationService($this->conversations, $this->participants, $permission, $this->memberProvider(9), new RateLimiterFactory([
            'id' => 'race',
            'policy' => 'no_limit',
        ], new InMemoryStorage()), new ChatTransaction($this->connection), new ChatEventDispatcher($dispatcher, new NullLogger()));
        $conversation = $service->openWith(9, 7);
        self::assertSame([7, 9], $this->participants->memberIds($conversation->id));
        self::assertCount(1, $this->conversations->listForMember(9, 10));
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testMessageWindowsStayWithinConversationAndDoNotSkipUnseenMessages(): void
    {
        $conversation = $this->createConversation();
        $ids = [];
        for ($i = 0; $i < 6; ++$i) {
            $ids[] = $this->messages->insert($conversation->id, 7, 'message ' . $i, 100)->id;
        }

        $other = $this->createConversation(3, 9);
        $this->messages->insert($other->id, 3, 'private', 100);
        self::assertSame(\array_slice($ids, -2), array_column($this->messages->window($conversation->id, 2), 'id'));
        self::assertSame([$ids[2], $ids[3]], array_column($this->messages->window($conversation->id, 2, before: $ids[4]), 'id'));
        self::assertSame([$ids[1], $ids[2]], array_column($this->messages->window($conversation->id, 2, after: $ids[0]), 'id'));
        self::assertSame([], $this->messages->window($conversation->id, 2, after: $ids[5]));
    }

    public function testUnreadCountsExcludeOwnReadAndMutedMessages(): void
    {
        $conversation = $this->createConversation();
        $first = $this->messages->insert($conversation->id, 7, 'read', 100);
        $this->participants->markRead($conversation->id, 9, $first->id, 100, 42);
        $this->messages->insert($conversation->id, 9, 'own', 100);
        $last = $this->messages->insert($conversation->id, 7, 'unread', 101);
        $this->conversations->updateLastMessage($conversation, $last);
        self::assertSame(1, $this->participants->unreadCount(9));
        $item = $this->conversations->listForMember(9, 10)[0];
        self::assertSame(1, $item->unreadCount);
        self::assertSame(7, $item->partnerId);
        self::assertEquals($last, $item->lastMessage);
        $mute = new MuteService($this->conversations, $this->participants, $this->memberProvider(9), new ChatTransaction($this->connection));
        $mute->setMuted($conversation->id, 9, true);
        self::assertSame(0, $this->participants->unreadCount(9));
        $item = $this->conversations->listForMember(9, 10)[0];
        self::assertTrue($item->muted);
        self::assertSame(0, $item->unreadCount);
        $mute->setMuted($conversation->id, 9, false);
        self::assertSame(1, $this->participants->unreadCount(9));
    }

    public function testConversationPaginationUsesIdTieBreakerAndInclusiveSince(): void
    {
        $first = $this->createConversation();
        $second = $this->createConversation(3, 9);
        foreach ([$first, $second] as $conversation) {
            $this->conversations->updateLastMessage($conversation, $this->messages->insert($conversation->id, 9, 'same second', 100));
        }

        self::assertSame($second->id, $this->conversations->listForMember(9, 1)[0]->conversation->id);
        self::assertSame($first->id, $this->conversations->listForMember(9, 1, 100, $second->id)[0]->conversation->id);
        self::assertCount(2, $this->conversations->listForMember(9, 1, since: 100));
        self::assertSame([100, 100], array_column($this->conversations->listForMember(9, 1, since: 100), 'changedAt'));
        self::assertSame([], $this->conversations->listForMember(9, 10, since: 101));
        self::assertSame([], $this->conversations->listForMember(99, 10));
    }

    public function testSinceIncludesReadAndMuteChangesFromAnotherSession(): void
    {
        $conversation = $this->createConversation();
        $message = $this->messages->insert($conversation->id, 7, 'unread', 100);
        $this->conversations->updateLastMessage($conversation, $message);
        self::assertSame(100, $this->conversations->listForMember(9, 10)[0]->changedAt);
        $other = DriverManager::getConnection($this->connection->getParams());
        try {
            $participants = new ParticipantGateway($other);
            $participants->markRead($conversation->id, 9, $message->id, 200, 42);
            $items = $this->conversations->listForMember(9, 10, since: 200);
            self::assertCount(1, $items);
            self::assertSame(200, $items[0]->changedAt);
            self::assertSame(100, $items[0]->conversation->lastMessageAt);
            self::assertSame(0, $items[0]->unreadCount);
            $participants->setMuted($conversation->id, 9, true, 300);
            $items = $this->conversations->listForMember(9, 10, since: 300);
            self::assertTrue($items[0]->muted);
            self::assertSame(300, $items[0]->changedAt);
            self::assertSame([], $this->conversations->listForMember(7, 10, since: 200));
        } finally {
            $other->close();
        }
    }

    public function testReaderHistoryWindowsExposeOnlyDeliveredCursors(): void
    {
        $conversation = $this->createConversation();
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->messages->insert($conversation->id, 7, 'history', 100 + $i * 86400)->id;
        }

        $second = $this->createConversation(3, 9);
        $third = $this->createConversation(4, 9);
        $options = new ChatOptions(pageSize: 2);
        $resolver = new ContactResolver(
            self::createStub(ContactGatewayInterface::class),
            new ContactFactory($options, self::createStub(Studio::class), self::createStub(ContaoFramework::class)),
            self::createStub(TranslatorInterface::class),
        );
        $views = new ChatViewFactory($resolver,
            new ChatPageUrlGenerator(self::createStub(ContaoFramework::class), self::createStub(ContentUrlGenerator::class)),
            new DaySeparatorFactory(self::createStub(TranslatorInterface::class)),
        );
        $reads = new ReadTracker($this->conversations, $this->participants, $this->messages, $this->memberProvider(9), new ChatTransaction($this->connection), new ChatEventDispatcher(new EventDispatcher(), new NullLogger()));
        $reader = new ChatReader($this->conversations, $this->messages, $this->participants, $reads, $options, $views);
        $page = $this->createClassWithPropertiesStub(PageModel::class, [
            'id' => 42,
            'language' => 'en',
            'dateFormat' => 'Y-m-d',
        ]);
        $initial = $reader->read($page, 9, $conversation, includeList: true, includeMessages: true);
        self::assertSame([$ids[3], $ids[4]], array_column($initial->messages, 'id'));
        self::assertSame($ids[3], $initial->beforeMessageId);
        self::assertSame('0,' . $second->id, $initial->beforeConversation);
        self::assertSame([$third->uuid, $second->uuid], array_column($initial->conversations, 'uuid'));
        $older = $reader->read($page, 9, $conversation, includeMessages: true, before: $initial->beforeMessageId);
        self::assertSame([$ids[1], $ids[2]], array_column($older->messages, 'id'));
        self::assertSame($ids[1], $older->beforeMessageId);
        $oldest = $reader->read($page, 9, $conversation, includeMessages: true, before: $older->beforeMessageId);
        self::assertSame([$ids[0]], array_column($oldest->messages, 'id'));
        self::assertNull($oldest->beforeMessageId);
        self::assertSame($ids[4], $this->participants->state($conversation->id, 9)['lastReadMessageId'] ?? null);
        $lastList = $reader->read($page, 9, includeList: true, beforeTimestamp: 0, beforeId: $second->id);
        self::assertSame([$conversation->uuid], array_column($lastList->conversations, 'uuid'));
        self::assertNull($lastList->beforeConversation);
        $after = $reader->read($page, 9, $conversation, includeMessages: true, after: $ids[0]);
        self::assertSame([$ids[1], $ids[2]], array_column($after->messages, 'id'));
        self::assertSame($ids[2], $after->lastMessageId);
        self::assertNull($after->beforeMessageId);
    }

    public function testIdleActivityIsThrottledWithoutChangingListCursor(): void
    {
        $conversation = $this->createConversation();
        $message = $this->messages->insert($conversation->id, 7, 'read', 100);
        self::assertTrue($this->participants->markRead($conversation->id, 9, $message->id, 200, 42));
        self::assertFalse($this->participants->markRead($conversation->id, 9, 0, 229, 43));
        self::assertSame(200, $this->participants->state($conversation->id, 9)['lastReadAt'] ?? null);
        self::assertSame(42, $this->participants->state($conversation->id, 9)['lastPageId']);
        self::assertFalse($this->participants->markRead($conversation->id, 9, 0, 230, 43));
        self::assertSame(230, $this->participants->state($conversation->id, 9)['lastReadAt'] ?? null);
        self::assertSame(43, $this->participants->state($conversation->id, 9)['lastPageId']);
        self::assertSame(200, $this->conversations->listForMember(9, 10)[0]->changedAt);
        $next = $this->messages->insert($conversation->id, 7, 'next', 231);
        self::assertTrue($this->participants->markRead($conversation->id, 9, $next->id, 231, 44));
        self::assertSame(231, $this->conversations->listForMember(9, 10)[0]->changedAt);
        $this->participants->setMuted($conversation->id, 9, true, 240);
        $this->participants->setMuted($conversation->id, 9, true, 250);
        self::assertSame(240, $this->conversations->listForMember(9, 10)[0]->changedAt);
        $this->participants->setMuted($conversation->id, 9, false, 260);
        self::assertSame(260, $this->conversations->listForMember(9, 10)[0]->changedAt);
        $unthrottled = new ParticipantGateway($this->connection, new ChatOptions(activityThrottle: 0));
        $unthrottled->markRead($conversation->id, 9, 0, 261, 45);
        self::assertSame(261, $unthrottled->state($conversation->id, 9)['lastReadAt'] ?? null);
        self::assertSame(260, $this->conversations->listForMember(9, 10)[0]->changedAt);
    }

    public function testReadPositionNeverRegressesAndTracksPage(): void
    {
        $conversation = $this->createConversation();
        $first = $this->messages->insert($conversation->id, 7, 'first', 100);
        $last = $this->messages->insert($conversation->id, 7, 'last', 100);
        $tracker = new ReadTracker($this->conversations, $this->participants, $this->messages, $this->memberProvider(9), new ChatTransaction($this->connection), new ChatEventDispatcher(new EventDispatcher(), new NullLogger()));
        $tracker->markRead($conversation->id, 9, $last->id, 42);
        $tracker->markRead($conversation->id, 9, $first->id, 43);

        $state = $this->participants->state($conversation->id, 9);
        self::assertNotNull($state);
        self::assertSame($last->id, $state['lastReadMessageId']);
        // Activity-only page changes are throttled along with lastReadAt.
        self::assertSame(42, $state['lastPageId']);
        self::assertSame(0, $this->participants->unreadCount(9));
    }

    public function testErasurePreservesTextAndDeletesEmptyConversation(): void
    {
        $conversation = $this->createConversation();
        $message = $this->messages->insert($conversation->id, 7, 'history', 100);
        $this->conversations->updateLastMessage($conversation, $message);
        $eraser = new MemberDataEraser($this->conversations, $this->participants, $this->messages, new ChatTransaction($this->connection));
        $eraser->erase(7);
        $eraser->erase(7);
        self::assertSame([9], $this->participants->memberIds($conversation->id));
        self::assertSame('history', $this->messages->find($message->id)?->body);
        self::assertSame(0, $this->messages->find($message->id)->authorId);
        self::assertSame(0, $this->conversations->listForMember(9, 10)[0]->partnerId);
        $eraser->erase(9);
        self::assertNull($this->conversations->find($conversation->id));
        self::assertNull($this->messages->find($message->id));
    }

    public function testGatewayDeletionCascadesWithoutTouchingOtherConversations(): void
    {
        $conversation = $this->createConversation();
        $other = $this->createConversation(3, 9);
        $message = $this->messages->insert($conversation->id, 7, 'delete', 100);
        $this->connection->transactional(fn () => $this->conversations->delete($conversation->id));
        self::assertNull($this->messages->find($message->id));
        self::assertSame([], $this->participants->memberIds($conversation->id));
        self::assertEquals($other, $this->conversations->find($other->id));
    }

    public function testFailedTransactionRollsBackAllWrites(): void
    {
        try {
            new ChatTransaction($this->connection)->run(function (): never {
                $this->createConversation();
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException $runtimeException) {
            self::assertSame('abort', $runtimeException->getMessage());
        }

        self::assertSame([], $this->conversations->listForMember(9, 10));
        self::assertSame([], $this->participants->conversationIds(9));
    }

    public function testConversationLockBlocksAnotherWriter(): void
    {
        $conversation = $this->createConversation();
        $other = DriverManager::getConnection($this->connection->getParams());
        $other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->connection->beginTransaction();
        try {
            self::assertNotNull($this->conversations->find($conversation->id, true));
            try {
                $other->executeStatement('UPDATE tl_chat_conversation SET tstamp = 999 WHERE id = ?', [$conversation->id]);
                self::fail('Expected the other writer to wait for the conversation lock.');
            } catch (LockWaitTimeoutException) {
                self::assertTrue($this->connection->isTransactionActive());
            }
        } finally {
            $this->connection->rollBack();
            $other->close();
        }
    }

    private function createConversation(int $low = 7, int $high = 9): Conversation
    {
        $conversation = $this->conversations->insert(Uuid::v7()->toRfc4122(), $low, $high, 100);
        $this->participants->add($conversation->id, $low, 100);
        $this->participants->add($conversation->id, $high, 100);

        return $conversation;
    }
}
