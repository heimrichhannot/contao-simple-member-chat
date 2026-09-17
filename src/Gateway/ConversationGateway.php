<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Gateway;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\ConversationListItem;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use Symfony\Component\Uid\Uuid;

/** @phpstan-type ConversationRow array{id: int|string, uuid: string, memberLow: int|string, memberHigh: int|string, createdAt: int|string, lastMessageAt: int|string, lastMessageId: int|string} */
final readonly class ConversationGateway implements ConversationGatewayInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function find(int $id, bool $forUpdate = false): ?Conversation
    {
        /** @var ConversationRow|false $row */
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_chat_conversation WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByUuid(string $uuid): ?Conversation
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        /** @var ConversationRow|false $row */
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_chat_conversation WHERE uuid = ?', [Uuid::fromString($uuid)->toBinary()], [ParameterType::BINARY]);

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByPair(int $memberLow, int $memberHigh): ?Conversation
    {
        /** @var ConversationRow|false $row */
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_chat_conversation WHERE memberLow = ? AND memberHigh = ?', [$memberLow, $memberHigh]);

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(string $uuid, int $memberLow, int $memberHigh, int $now): Conversation
    {
        $this->connection->insert('tl_chat_conversation', [
            'uuid' => Uuid::fromString($uuid)->toBinary(),
            'tstamp' => $now,
            'memberLow' => $memberLow,
            'memberHigh' => $memberHigh,
            'createdAt' => $now,
        ], [
            'uuid' => ParameterType::BINARY,
        ]);

        return new Conversation((int) $this->connection->lastInsertId(), $uuid, $memberLow, $memberHigh, $now, 0, 0);
    }

    public function updateLastMessage(Conversation $conversation, Message $message): Conversation
    {
        $this->connection->update('tl_chat_conversation', [
            'lastMessageId' => $message->id,
            'lastMessageAt' => $message->createdAt,
            'tstamp' => $message->createdAt,
        ], [
            'id' => $conversation->id,
        ]);

        return new Conversation($conversation->id, $conversation->uuid, $conversation->memberLow, $conversation->memberHigh, $conversation->createdAt, $message->createdAt, $message->id);
    }

    /**
     * @return list<ConversationListItem>
     *
     * The before cursor is (lastMessageAt, id). Since is inclusive so a poll
     * cannot lose activity occurring in the same Unix second; callers upsert by UUID.
     */
    public function listForMember(int $memberId, int $limit, ?int $beforeTimestamp = null, ?int $beforeId = null, ?int $since = null): array
    {
        if ($limit < 1 || (($beforeTimestamp === null) !== ($beforeId === null)) || ($since !== null && $beforeTimestamp !== null)) {
            throw new \InvalidArgumentException('Invalid conversation window.');
        }

        $sql = <<<'SQL'
            SELECT c.*, p.muted,
                COALESCE(partner.member, 0) AS partnerId,
                last.body AS lastBody, last.author AS lastAuthor, last.createdAt AS lastCreatedAt,
                COALESCE(unread.total, 0) AS unreadCount
            FROM tl_chat_conversation c
            INNER JOIN tl_chat_participant p ON p.pid = c.id AND p.member = :member
            LEFT JOIN tl_chat_participant partner ON partner.pid = c.id AND partner.member <> p.member
            LEFT JOIN tl_chat_message last ON last.id = c.lastMessageId AND last.pid = c.id
            LEFT JOIN (
                SELECT reader.pid, COUNT(m.id) AS total
                FROM tl_chat_participant reader
                INNER JOIN tl_chat_message m ON m.pid = reader.pid AND m.id > reader.lastReadMessageId AND m.author <> reader.member
                WHERE reader.member = :member AND reader.muted = ''
                GROUP BY reader.pid
            ) unread ON unread.pid = c.id
            WHERE 1 = 1
            SQL;
        $parameters = [
            'member' => $memberId,
        ];
        if ($beforeTimestamp !== null && $beforeId !== null) {
            $sql .= ' AND (c.lastMessageAt < :before OR (c.lastMessageAt = :before AND c.id < :beforeId))';
            $parameters += [
                'before' => $beforeTimestamp,
                'beforeId' => $beforeId,
            ];
        }

        if ($since !== null) {
            $sql .= ' AND c.lastMessageAt >= :since';
            $parameters['since'] = $since;
        }

        $sql .= ' ORDER BY c.lastMessageAt DESC, c.id DESC';
        // Incremental polling returns all changed entries, never a truncated page.
        if ($since === null) {
            $sql .= ' LIMIT ' . $limit;
        }

        /** @var list<array{id: int|string, uuid: string, memberLow: int|string, memberHigh: int|string, createdAt: int|string, lastMessageAt: int|string, lastMessageId: int|string, muted: string, partnerId: int|string, lastBody: ?string, lastAuthor: int|string|null, lastCreatedAt: int|string|null, unreadCount: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $parameters);
        $items = [];
        foreach ($rows as $row) {
            $conversation = $this->hydrate($row);
            $last = $row['lastBody'] === null ? null : new Message($conversation->lastMessageId, $conversation->id, (int) $row['lastAuthor'], $row['lastBody'], (int) $row['lastCreatedAt']);
            $items[] = new ConversationListItem($conversation, (int) $row['partnerId'], $last, (int) $row['unreadCount'], $row['muted'] !== '');
        }

        return $items;
    }

    public function delete(int $conversationId): void
    {
        $this->connection->delete('tl_chat_message', [
            'pid' => $conversationId,
        ]);
        $this->connection->delete('tl_chat_participant', [
            'pid' => $conversationId,
        ]);
        $this->connection->delete('tl_chat_conversation', [
            'id' => $conversationId,
        ]);
    }

    /**
     * @param ConversationRow $row
     */
    private function hydrate(array $row): Conversation
    {
        return new Conversation((int) $row['id'], Uuid::fromBinary($row['uuid'])->toRfc4122(), (int) $row['memberLow'], (int) $row['memberHigh'], (int) $row['createdAt'], (int) $row['lastMessageAt'], (int) $row['lastMessageId']);
    }
}
