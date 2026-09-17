<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/** Stable public integration event; dispatched after the owning transaction commits. */
final class MessagesReadEvent extends Event
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $memberId,
        public readonly int $upToMessageId,
    ) {
    }
}
