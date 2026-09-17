<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\ChatMessage;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

final readonly class ConfigOnDeleteListener
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[AsCallback(table: 'tl_chat_message', target: 'config.ondelete')]
    public function __invoke(DataContainer $dc): void
    {
        /** @var array{id: int|string, pid: int|string}|null $row */
        $row = $dc->getCurrentRecord();
        if ($row === null) {
            return;
        }

        // DC_Table calls ondelete BEFORE DELETE. Exclude the soon-to-be-deleted
        // row; a conditional atomic update cannot overwrite a concurrent send.
        $this->connection->executeStatement(<<<'SQL'
            UPDATE tl_chat_conversation SET
                lastMessageId = COALESCE((SELECT id FROM tl_chat_message WHERE pid = ? AND id <> ? ORDER BY id DESC LIMIT 1), 0),
                lastMessageAt = COALESCE((SELECT createdAt FROM tl_chat_message WHERE pid = ? AND id <> ? ORDER BY id DESC LIMIT 1), 0),
                tstamp = ?
            WHERE id = ? AND lastMessageId = ?
            SQL, [(int) $row['pid'], (int) $row['id'], (int) $row['pid'], (int) $row['id'], time(), (int) $row['pid'], (int) $row['id']]);
        // A removed unread message changes counts even when it was not the tail.
        $this->connection->update('tl_chat_participant', [
            'tstamp' => time(),
        ], [
            'pid' => (int) $row['pid'],
        ]);
    }
}
