<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Gateway;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\ConversationListItem;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;

interface ConversationGatewayInterface
{
    public function find(int $id, bool $forUpdate = false): ?Conversation;

    public function findByUuid(string $uuid): ?Conversation;

    public function findByPair(int $memberLow, int $memberHigh): ?Conversation;

    public function insert(string $uuid, int $memberLow, int $memberHigh, int $now): Conversation;

    public function updateLastMessage(Conversation $conversation, Message $message): Conversation;

    /**
     * @return list<ConversationListItem>
     *
     * The before cursor is (lastMessageAt, id). Since is inclusive so a poll
     * cannot lose activity occurring in the same Unix second; callers upsert by UUID.
     */
    public function listForMember(int $memberId, int $limit, ?int $beforeTimestamp = null, ?int $beforeId = null, ?int $since = null): array;

    public function delete(int $conversationId): void;
}
