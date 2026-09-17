<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

interface ContactPermissionInterface
{
    public function canContact(int $initiatorId, int $memberId): bool;
}
