<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View\Model;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;

final readonly class ConversationItemView
{
    public function __construct(
        public string $uuid,
        public Contact $partner,
        public string $url,
        public string $excerpt,
        public int $lastMessageAt,
        public int $changedAt,
        public int $unreadCount,
        public bool $muted,
    ) {
    }
}
