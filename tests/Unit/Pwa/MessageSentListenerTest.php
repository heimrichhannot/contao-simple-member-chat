<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit\Pwa;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\Event\MessageSentEvent;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\MessageSentListener;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\PushOptions;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\SendChatPushMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

final class MessageSentListenerTest extends TestCase
{
    public function testMessageDeclaresTheContaoLowPriorityTransport(): void
    {
        $attributes = new \ReflectionClass(SendChatPushMessage::class)->getAttributes(AsMessage::class);
        self::assertCount(1, $attributes);
        self::assertSame('contao_prio_low', $attributes[0]->newInstance()->transport);
        // The deprecated marker interface must not come back.
        self::assertSame([], class_implements(SendChatPushMessage::class));
    }

    public function testEnqueuesOnlyIdsOnLowPriorityTransport(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $queued): Envelope {
            self::assertInstanceOf(SendChatPushMessage::class, $queued);
            self::assertSame(42, $queued->messageId);
            self::assertSame([9, 10], $queued->recipientIds);
            // The handler may run without a request, so the host travels with the message.
            self::assertSame('https://example.org', $queued->baseUrl);

            return new Envelope($queued);
        });
        new MessageSentListener($bus, new PushOptions(true, 3), $this->requests())($this->event());
    }

    public function testContaoTransportRoutingQueuesWithoutCallingHandler(): void
    {
        $transport = $this->createMock(SenderInterface::class);
        $transport->expects(self::once())->method('send')->willReturnArgument(0);
        $handlerMiddleware = $this->createMock(MiddlewareInterface::class);
        $handlerMiddleware->expects(self::never())->method('handle');
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator(
                [
                    SendChatPushMessage::class => ['contao_prio_low'],
                ],
                new ServiceLocator([
                    'contao_prio_low' => static fn (): SenderInterface => $transport,
                ]),
            )),
            $handlerMiddleware,
        ]);
        new MessageSentListener($bus, new PushOptions(true, 3), $this->requests())($this->event());
    }

    public function testDisabledOrUnconfiguredDoesNotEnqueue(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        new MessageSentListener($bus, new PushOptions(), $this->requests())($this->event());
        new MessageSentListener($bus, new PushOptions(true), $this->requests())($this->event());
    }

    public function testWithoutARequestNoHostIsClaimed(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (SendChatPushMessage $queued): Envelope {
            self::assertNull($queued->baseUrl);

            return new Envelope($queued);
        });
        new MessageSentListener($bus, new PushOptions(true, 3), $this->requests(null))($this->event());
    }

    private function requests(?string $host = 'https://example.org'): RequestStack
    {
        $stack = new RequestStack();
        if ($host !== null) {
            $stack->push(Request::create($host . '/chat'));
        }

        return $stack;
    }

    private function event(): MessageSentEvent
    {
        return new MessageSentEvent(new Message(42, 1, 7, 'Private text', 100), new Conversation(1, 'uuid', 7, 9, 100, 100, 42), 7, [9, 10]);
    }
}
