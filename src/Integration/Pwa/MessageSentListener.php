<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa;

use HeimrichHannot\SimpleMemberChatBundle\Event\MessageSentEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener]
final readonly class MessageSentListener
{
    public function __construct(
        private MessageBusInterface $bus,
        private PushOptions $options,
        private RequestStack $requests,
    ) {
    }

    public function __invoke(MessageSentEvent $event): void
    {
        if ($this->options->enabled && $this->options->configuration > 0 && $event->recipientIds !== []) {
            // Captured here because the handler may run in a worker without a request.
            $this->bus->dispatch(new SendChatPushMessage(
                $event->message->id,
                $event->recipientIds,
                $this->requests->getMainRequest()?->getSchemeAndHttpHost(),
            ));
        }
    }
}
