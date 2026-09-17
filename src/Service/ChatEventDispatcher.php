<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/** Called only after a successful top-level transaction commit. */
final readonly class ChatEventDispatcher
{
    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function dispatch(object $event): void
    {
        try {
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $throwable) {
            $this->logger->error('A chat event listener failed after commit.', [
                'event' => $event::class,
                'exception' => $throwable,
            ]);
        }
    }
}
