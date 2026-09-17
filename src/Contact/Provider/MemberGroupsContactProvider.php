<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Contact\Provider;

use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderInterface;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Viewer;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;

final readonly class MemberGroupsContactProvider implements ContactProviderInterface
{
    /**
     * @var list<int>
     */
    private array $groups;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private ContactGatewayInterface $members,
        private ContactFactory $factory,
        array $options,
    ) {
        if (array_diff(array_keys($options), ['groups']) !== [] || !\is_array($options['groups'] ?? null) || !array_is_list($options['groups'])) {
            throw new \InvalidArgumentException('member_groups expects a groups list.');
        }

        foreach ($options['groups'] as $id) {
            if (!\is_int($id) || $id <= 0) {
                throw new \InvalidArgumentException('member_groups.groups must contain positive integers.');
            }
        }

        $this->groups = array_values(array_unique($options['groups']));
    }

    public static function getAlias(): string
    {
        return 'member_groups';
    }

    public function search(Viewer $viewer, string $query, int $limit): array
    {
        return $this->factory->fromMemberRows($this->members->search($this->groups, $query, $limit));
    }

    public function canContact(Viewer $viewer, int $memberId): bool
    {
        return $this->members->canContact($this->groups, $memberId);
    }
}
