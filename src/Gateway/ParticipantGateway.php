<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Gateway;

use Doctrine\DBAL\Connection;

final readonly class ParticipantGateway implements ParticipantGatewayInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function add(int $conversationId, int $memberId, int $now): void
    {
        $this->connection->insert('tl_chat_participant', [
            'pid' => $conversationId,
            'member' => $memberId,
            'tstamp' => $now,
            'joinedAt' => $now,
        ]);
    }

    /**
     * @return list<int>
     */
    public function memberIds(int $conversationId): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT member FROM tl_chat_participant WHERE pid = ? ORDER BY member', [$conversationId]);

        return array_map(intval(...), $ids);
    }

    /**
     * @return list<int>
     */
    public function conversationIds(int $memberId): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn('SELECT pid FROM tl_chat_participant WHERE member = ? ORDER BY pid', [$memberId]);

        return array_map(intval(...), $ids);
    }

    /**
     * @return array{lastReadAt: int, lastReadMessageId: int, lastPageId: int, muted: bool}|null
     */
    public function state(int $conversationId, int $memberId): ?array
    {
        /** @var array{lastReadAt: int|string, lastReadMessageId: int|string, lastPageId: int|string, muted: string}|false $row */
        $row = $this->connection->fetchAssociative('SELECT lastReadAt, lastReadMessageId, lastPageId, muted FROM tl_chat_participant WHERE pid = ? AND member = ?', [$conversationId, $memberId]);

        return $row === false ? null : [
            'lastReadAt' => (int) $row['lastReadAt'],
            'lastReadMessageId' => (int) $row['lastReadMessageId'],
            'lastPageId' => (int) $row['lastPageId'],
            'muted' => $row['muted'] !== '',
        ];
    }

    public function markRead(int $conversationId, int $memberId, int $upToMessageId, int $now, ?int $pageId): bool
    {
        $changed = $this->connection->executeStatement('UPDATE tl_chat_participant SET lastReadMessageId = ?, lastReadAt = ?, tstamp = ? WHERE pid = ? AND member = ? AND lastReadMessageId < ?', [$upToMessageId, $now, $now, $conversationId, $memberId, $upToMessageId]) > 0;
        // Activity and page tracking also work for empty polls and older history.
        $data = [
            'lastReadAt' => $now,
            'tstamp' => $now,
        ];
        if ($pageId !== null) {
            $data['lastPageId'] = $pageId;
        }

        $this->connection->update('tl_chat_participant', $data, [
            'pid' => $conversationId,
            'member' => $memberId,
        ]);

        return $changed;
    }

    public function setMuted(int $conversationId, int $memberId, bool $muted, int $now): void
    {
        $this->connection->update('tl_chat_participant', [
            'muted' => $muted ? '1' : '',
            'tstamp' => $now,
        ], [
            'pid' => $conversationId,
            'member' => $memberId,
        ]);
    }

    public function unreadCount(int $memberId): int
    {
        /** @var int|string $count */
        $count = $this->connection->fetchOne("SELECT COUNT(m.id) FROM tl_chat_participant p INNER JOIN tl_chat_message m ON m.pid = p.pid AND m.id > p.lastReadMessageId AND m.author <> p.member WHERE p.member = ? AND p.muted = ''", [$memberId]);

        return (int) $count;
    }

    public function remove(int $conversationId, int $memberId): void
    {
        $this->connection->delete('tl_chat_participant', [
            'pid' => $conversationId,
            'member' => $memberId,
        ]);
    }
}
