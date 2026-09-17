<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Gateway;

interface ParticipantGatewayInterface
{
    public function add(int $conversationId, int $memberId, int $now): void;

    /**
     * @return list<int>
     */
    public function memberIds(int $conversationId): array;

    /**
     * @return list<int>
     */
    public function conversationIds(int $memberId): array;

    /**
     * @return array{lastReadAt: int, lastReadMessageId: int, lastPageId: int, muted: bool}|null
     */
    public function state(int $conversationId, int $memberId): ?array;

    public function markRead(int $conversationId, int $memberId, int $upToMessageId, int $now, ?int $pageId): bool;

    public function setMuted(int $conversationId, int $memberId, bool $muted, int $now): void;

    public function unreadCount(int $memberId): int;

    public function remove(int $conversationId, int $memberId): void;
}
