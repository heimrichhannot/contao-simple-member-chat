<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa;

final readonly class PushOptions
{
    public function __construct(
        public bool $enabled = false,
        public int $configuration = 0,
        public int $activeRecipientGrace = 60,
        public int $bodyLength = 0,
    ) {
    }
}
