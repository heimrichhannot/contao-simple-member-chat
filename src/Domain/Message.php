<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Domain;

final readonly class Message
{
    public function __construct(
        public int $id,
        public int $conversationId,
        public int $authorId,
        public string $body,
        public int $createdAt,
    ) {
    }
}
