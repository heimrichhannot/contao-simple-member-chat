<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

/** Phase 2 replaces this default with an adapter to ContactService. */
final class DenyContactPermission implements ContactPermissionInterface
{
    public function canContact(int $initiatorId, int $memberId): bool
    {
        return false;
    }
}
