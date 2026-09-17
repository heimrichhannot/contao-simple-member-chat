<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Contact\Provider;

use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderInterface;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Viewer;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;

final readonly class SharedGroupsContactProvider implements ContactProviderInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private ContactGatewayInterface $members,
        private ContactFactory $factory,
        array $options,
    ) {
        if ($options !== []) {
            throw new \InvalidArgumentException('shared_groups does not accept options.');
        }
    }

    public static function getAlias(): string
    {
        return 'shared_groups';
    }

    public function search(Viewer $viewer, string $query, int $limit): array
    {
        return $this->factory->fromMemberRows($this->members->search($viewer->groupIds, $query, $limit));
    }

    public function canContact(Viewer $viewer, int $memberId): bool
    {
        return $this->members->canContact($viewer->groupIds, $memberId);
    }
}
