<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View;

use HeimrichHannot\SimpleMemberChatBundle\View\Model\ConversationItemView;

final class ConversationPollFingerprint
{
    /**
     * @param list<ConversationItemView> $items
     */
    public static function create(array $items, int $since): string
    {
        // Normalize keys after filtering: the next inclusive window starts at zero.
        $boundary = array_values(array_filter($items, static fn (ConversationItemView $item): bool => $item->changedAt >= $since));

        return hash('sha256', json_encode([$since, $boundary], \JSON_THROW_ON_ERROR));
    }
}
