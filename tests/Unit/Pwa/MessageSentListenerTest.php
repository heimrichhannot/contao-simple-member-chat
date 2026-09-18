<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit\Pwa;

use Contao\CoreBundle\Messenger\Message\LowPriorityMessageInterface;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\Event\MessageSentEvent;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\MessageSentListener;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\PushOptions;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\SendChatPushMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;

final class MessageSentListenerTest extends TestCase
{
    public function testEnqueuesOnlyIdsOnLowPriorityTransport(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $queued): Envelope {
            self::assertInstanceOf(LowPriorityMessageInterface::class, $queued);
            self::assertInstanceOf(SendChatPushMessage::class, $queued);
            self::assertSame(42, $queued->messageId);
            self::assertSame([9, 10], $queued->recipientIds);

            return new Envelope($queued);
        });
        new MessageSentListener($bus, new PushOptions(true, 3))($this->event());
    }

    public function testContaoInterfaceRoutingQueuesWithoutCallingHandler(): void
    {
        $transport = $this->createMock(SenderInterface::class);
        $transport->expects(self::once())->method('send')->willReturnArgument(0);
        $handlerMiddleware = $this->createMock(MiddlewareInterface::class);
        $handlerMiddleware->expects(self::never())->method('handle');
        $bus = new MessageBus([
            new SendMessageMiddleware(new SendersLocator(
                [
                    LowPriorityMessageInterface::class => ['contao_prio_low'],
                ],
                new ServiceLocator([
                    'contao_prio_low' => static fn (): SenderInterface => $transport,
                ]),
            )),
            $handlerMiddleware,
        ]);
        new MessageSentListener($bus, new PushOptions(true, 3))($this->event());
    }

    public function testDisabledOrUnconfiguredDoesNotEnqueue(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        new MessageSentListener($bus, new PushOptions())($this->event());
        new MessageSentListener($bus, new PushOptions(true))($this->event());
    }

    private function event(): MessageSentEvent
    {
        return new MessageSentEvent(new Message(42, 1, 7, 'Private text', 100), new Conversation(1, 'uuid', 7, 9, 100, 100, 42), 7, [9, 10]);
    }
}
