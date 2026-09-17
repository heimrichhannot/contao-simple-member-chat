<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Contact;

final readonly class Viewer
{
    /**
     * @param list<int> $groupIds active member groups
     */
    public function __construct(
        public int $memberId,
        public array $groupIds,
    ) {
    }
}
