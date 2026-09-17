<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Configuration;

final readonly class ChatOptions
{
    /**
     * @param int|array{int, int, string} $avatarSize
     * @param array<string, mixed>        $providers
     */
    public function __construct(
        public string $contactProvider = 'member_groups',
        public int $messagesInterval = 4000,
        public int $conversationsInterval = 15000,
        public int $badgeInterval = 30000,
        public int $maxIntervalMultiplier = 8,
        public int $maxLength = 2000,
        public int $rateLimit = 30,
        public ?string $rateLimiter = null,
        public int $pageSize = 50,
        public int $searchLimit = 20,
        public int $searchMinLength = 2,
        public ?string $avatarField = null,
        public int|array $avatarSize = [96, 96, 'crop'],
        public array $providers = [
            'member_groups' => [
                'groups' => [],
            ],
        ],
        public int $activityThrottle = 30,
    ) {
    }
}
