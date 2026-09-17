<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Gateway;

use Contao\Date;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;

final class ContactGateway implements ContactGatewayInterface
{
    private const string ACTIVE = "disable = 0 AND login = 1 AND (start = '' OR start <= ?) AND (stop = '' OR stop > ?)";

    private ?string $displayColumns = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ChatOptions $options,
    ) {
    }

    public function findMembers(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        return $this->connection->fetchAllAssociative('SELECT ' . $this->displayColumns() . ' FROM tl_member WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
    }

    public function activeGroupIds(int $memberId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT `groups` FROM tl_member WHERE id = ?', [$memberId]);
        if ($row === false) {
            return null;
        }

        $ids = $this->groupIds($row['groups']);
        if ($ids === []) {
            return [];
        }

        $now = Date::floorToMinute();
        /** @var list<int|string> $active */
        $active = $this->connection->fetchFirstColumn("SELECT id FROM tl_member_group WHERE id IN (?) AND disable = 0 AND (start = '' OR start <= ?) AND (stop = '' OR stop > ?) ORDER BY id", [$ids, $now, $now], [ArrayParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER]);

        return array_map(intval(...), $active);
    }

    public function search(array $groupIds, string $query, int $limit): array
    {
        if ($groupIds === [] || $limit <= 0) {
            return [];
        }

        // Escape SQL wildcard characters: the query is a literal prefix.
        $prefix = strtr($query, [
            '!' => '!!',
            '%' => '!%',
            '_' => '!_',
        ]) . '%';
        $now = time();
        $rows = $this->connection->iterateAssociative('SELECT ' . $this->displayColumns() . ', `groups` FROM tl_member WHERE ' . self::ACTIVE . " AND (firstname LIKE ? ESCAPE '!' OR lastname LIKE ? ESCAPE '!' OR username LIKE ? ESCAPE '!') ORDER BY lastname, firstname, username, id", [$now, $now, $prefix, $prefix, $prefix], [ParameterType::INTEGER, ParameterType::INTEGER]);
        $matches = [];
        foreach ($rows as $row) {
            if (array_intersect($groupIds, $this->groupIds($row['groups'])) === []) {
                continue;
            }

            unset($row['groups']);
            $matches[] = $row;
            if (\count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }

    public function canContact(array $groupIds, int $memberId): bool
    {
        if ($groupIds === [] || $memberId <= 0) {
            return false;
        }

        $now = time();
        $row = $this->connection->fetchAssociative('SELECT `groups` FROM tl_member WHERE id = ? AND ' . self::ACTIVE, [$memberId, $now, $now], [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER]);

        return $row !== false && array_intersect($groupIds, $this->groupIds($row['groups'])) !== [];
    }

    /**
     * @return list<int>
     */
    private function groupIds(mixed $value): array
    {
        $ids = [];
        foreach (StringUtil::deserialize($value, true) as $id) {
            if ((\is_int($id) || (\is_string($id) && ctype_digit($id))) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function displayColumns(): string
    {
        if ($this->displayColumns !== null) {
            return $this->displayColumns;
        }

        $columns = ['id', 'firstname', 'lastname', 'username'];
        // A configured field may not yet exist (or may have been removed).
        if ($this->options->avatarField !== null && \array_key_exists(strtolower($this->options->avatarField), $this->connection->createSchemaManager()->listTableColumns('tl_member'))) {
            $columns[] = $this->options->avatarField;
        }

        return $this->displayColumns = implode(', ', array_map($this->connection->quoteIdentifier(...), array_unique($columns)));
    }
}
