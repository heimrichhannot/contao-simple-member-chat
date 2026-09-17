<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactService;

final readonly class ContactPermission implements ContactPermissionInterface
{
    public function __construct(
        private ContactService $contacts,
    ) {
    }

    public function canContact(int $initiatorId, int $memberId): bool
    {
        return $this->contacts->viewer()->memberId === $initiatorId && $this->contacts->canContact($memberId);
    }
}
