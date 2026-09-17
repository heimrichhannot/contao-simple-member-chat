<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Domain;

final readonly class Conversation
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $memberLow,
        public int $memberHigh,
        public int $createdAt,
        public int $lastMessageAt,
        public int $lastMessageId,
    ) {
    }
}
