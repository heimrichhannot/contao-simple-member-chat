<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\ChatMessage;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use HeimrichHannot\SimpleMemberChatBundle\Backend\MemberLabels;

final readonly class ListLabelLabelListener
{
    public function __construct(
        private MemberLabels $members,
    ) {
    }

    /**
     * @param array{author: int|string, createdAt: int|string, body: string} $row
     */
    #[AsCallback(table: 'tl_chat_message', target: 'list.label.label')]
    public function __invoke(array $row): string
    {
        $members = $this->members->forTable('tl_chat_message');
        $label = ($members[(int) $row['author']] ?? $members[0])->displayName . ' · ' . date('Y-m-d H:i', (int) $row['createdAt']) . ' · ' . mb_substr(str_replace(["\r", "\n"], ' ', $row['body']), 0, 100);

        return htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
