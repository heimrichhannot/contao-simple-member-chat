<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Gateway;

interface ContactGatewayInterface
{
    /** @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findMembers(array $ids): array;

    /**
     * @return list<int>|null null means that the member no longer exists
     */
    public function activeGroupIds(int $memberId): ?array;

    /** @param list<int> $groupIds
     * @return list<array<string, mixed>>
     */
    public function search(array $groupIds, string $query, int $limit): array;

    /**
     * @param list<int> $groupIds
     */
    public function canContact(array $groupIds, int $memberId): bool;
}
