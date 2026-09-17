<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Asset;

use HeimrichHannot\EncoreContracts\EncoreEntry;
use HeimrichHannot\EncoreContracts\EncoreExtensionInterface;
use HeimrichHannot\SimpleMemberChatBundle\HeimrichHannotSimpleMemberChatBundle;

final class EncoreExtension implements EncoreExtensionInterface
{
    public const string ENTRY = 'huh_member_chat';

    public function getBundle(): string
    {
        return HeimrichHannotSimpleMemberChatBundle::class;
    }

    /**
     * @return list<EncoreEntry>
     */
    public function getEntries(): array
    {
        return [EncoreEntry::create(self::ENTRY, 'assets/js/member_chat.js')->setRequiresCss(true)];
    }
}
