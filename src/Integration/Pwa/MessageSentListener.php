<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa;

use HeimrichHannot\SimpleMemberChatBundle\Event\MessageSentEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class MessageSentListener
{
    public function __construct(
        private MessageBusInterface $bus,
        private PushOptions $options,
    ) {
    }

    public function __invoke(MessageSentEvent $event): void
    {
        if ($this->options->enabled && $this->options->configuration > 0 && $event->recipientIds !== []) {
            $this->bus->dispatch(new SendChatPushMessage($event->message->id, $event->recipientIds));
        }
    }
}
