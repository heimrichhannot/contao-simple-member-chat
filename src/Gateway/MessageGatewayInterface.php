<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Gateway;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;

interface MessageGatewayInterface
{
    /**
     * Stable public integration API; deleted messages return null.
     */
    public function find(int $id): ?Message;

    /**
     * @return list<Message> always chronological; after returns the earliest unseen page
     */
    public function window(int $conversationId, int $limit, ?int $before = null, ?int $after = null): array;

    public function insert(int $conversationId, int $authorId, string $body, int $now): Message;

    public function anonymize(int $memberId): void;
}
