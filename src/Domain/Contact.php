<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Domain;

final readonly class Contact
{
    public function __construct(
        public int $memberId,
        public string $displayName,
        public ?string $subtitle = null,
        public ?string $avatarUrl = null,
    ) {
    }
}
