<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View\Model;

final readonly class DaySeparatorView
{
    public function __construct(
        public string $day,
        public string $label,
    ) {
    }
}
