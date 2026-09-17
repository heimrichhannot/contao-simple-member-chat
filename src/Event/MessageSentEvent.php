<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Event;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use Symfony\Contracts\EventDispatcher\Event;

final class MessageSentEvent extends Event
{
    /**
     * @param list<int> $recipientIds
     */
    public function __construct(
        public readonly Message $message,
        public readonly Conversation $conversation,
        public readonly int $authorId,
        public readonly array $recipientIds,
    ) {
    }
}
