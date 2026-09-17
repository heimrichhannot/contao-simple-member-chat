<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\ChatConversation;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use HeimrichHannot\SimpleMemberChatBundle\Backend\MemberLabels;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ListLabelLabelListener
{
    public function __construct(
        private MemberLabels $members,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array{memberLow: int|string, memberHigh: int|string, lastMessageAt: int|string} $row
     */
    #[AsCallback(table: 'tl_chat_conversation', target: 'list.label.label')]
    public function __invoke(array $row): string
    {
        $members = $this->members->forTable('tl_chat_conversation');
        $label = $this->translator->trans('tl_chat_conversation.summary', [
            '%first%' => ($members[(int) $row['memberLow']] ?? $members[0])->displayName,
            '%second%' => ($members[(int) $row['memberHigh']] ?? $members[0])->displayName,
            '%time%' => (int) $row['lastMessageAt'] > 0 ? date('Y-m-d H:i', (int) $row['lastMessageAt']) : '—',
        ], 'contao_tl_chat_conversation');

        return htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
