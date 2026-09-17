<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Domain;

final readonly class ConversationListItem
{
    public function __construct(
        public Conversation $conversation,
        public int $partnerId,
        public ?Message $lastMessage,
        public int $unreadCount,
        public bool $muted,
    ) {
    }
}
