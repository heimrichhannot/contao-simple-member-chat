<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Backend;

use Doctrine\DBAL\Connection;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/** Batch member resolution once per request and table, including deleted members. */
final class MemberLabels
{
    /**
     * @var \WeakMap<Request, array<string, array<int, Contact>>>
     */
    private \WeakMap $cache;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContactResolver $contacts,
        private readonly RequestStack $requests,
    ) {
        $this->cache = new \WeakMap();
    }

    /**
     * @return array<int, Contact>
     */
    public function forTable(string $table): array
    {
        $request = $this->requests->getCurrentRequest() ?? throw new \LogicException('Backend labels need a request.');
        $cached = $this->cache[$request] ?? [];
        if (!isset($cached[$table])) {
            $sql = match ($table) {
                'tl_chat_conversation' => 'SELECT memberLow AS member FROM tl_chat_conversation UNION SELECT memberHigh FROM tl_chat_conversation',
                'tl_chat_message' => 'SELECT DISTINCT author AS member FROM tl_chat_message',
                default => throw new \InvalidArgumentException('Unknown chat table.'),
            };
            /** @var list<int|string> $ids */
            $ids = $this->connection->fetchFirstColumn($sql);
            $cached[$table] = $this->contacts->resolveMany(array_values(array_unique([0, ...array_map(intval(...), $ids)])));
            $this->cache[$request] = $cached;
        }

        return $cached[$table];
    }
}
