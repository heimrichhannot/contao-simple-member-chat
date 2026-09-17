<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class ChatResponseListener
{
    // Also cover routing, CSRF and authorization failures rendered by the kernel.
    #[AsEventListener(priority: -1016)]
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || (!str_starts_with($event->getRequest()->getPathInfo(), '/_member_chat/') && !$event->getRequest()->attributes->getBoolean('_member_chat_badge'))) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary('Accept', false);
    }
}
