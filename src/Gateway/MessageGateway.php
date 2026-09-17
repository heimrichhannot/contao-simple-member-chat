<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Gateway;

use Doctrine\DBAL\Connection;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;

/** @phpstan-type MessageRow array{id: int|string, pid: int|string, author: int|string, body: string, createdAt: int|string} */
final readonly class MessageGateway implements MessageGatewayInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function find(int $id): ?Message
    {
        /** @var MessageRow|false $row */
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_chat_message WHERE id = ?', [$id]);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<Message> always chronological; after returns the earliest unseen page
     */
    public function window(int $conversationId, int $limit, ?int $before = null, ?int $after = null): array
    {
        if ($limit < 1 || ($before !== null && $after !== null)) {
            throw new \InvalidArgumentException('Invalid message window.');
        }

        $sql = 'SELECT * FROM tl_chat_message WHERE pid = ?';
        $parameters = [$conversationId];
        if ($before !== null) {
            $sql .= ' AND id < ?';
            $parameters[] = $before;
        }

        if ($after !== null) {
            $sql .= ' AND id > ?';
            $parameters[] = $after;
        }

        $sql .= ' ORDER BY id ' . ($after === null ? 'DESC' : 'ASC') . ' LIMIT ' . $limit;
        /** @var list<MessageRow> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $parameters);
        if ($after === null) {
            $rows = array_reverse($rows);
        }

        return array_map($this->hydrate(...), $rows);
    }

    public function insert(int $conversationId, int $authorId, string $body, int $now): Message
    {
        $this->connection->insert('tl_chat_message', [
            'pid' => $conversationId,
            'author' => $authorId,
            'body' => $body,
            'createdAt' => $now,
            'tstamp' => $now,
        ]);

        return new Message((int) $this->connection->lastInsertId(), $conversationId, $authorId, $body, $now);
    }

    public function anonymize(int $memberId): void
    {
        $this->connection->update('tl_chat_message', [
            'author' => 0,
        ], [
            'author' => $memberId,
        ]);
    }

    /**
     * @param MessageRow $row
     */
    private function hydrate(array $row): Message
    {
        return new Message((int) $row['id'], (int) $row['pid'], (int) $row['author'], $row['body'], (int) $row['createdAt']);
    }
}
