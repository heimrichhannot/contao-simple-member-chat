<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Event;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use Symfony\Contracts\EventDispatcher\Event;

/** Stable public integration event; dispatched after the owning transaction commits. */
final class ConversationCreatedEvent extends Event
{
    public function __construct(
        public readonly Conversation $conversation,
        public readonly int $initiatorId,
    ) {
    }
}
