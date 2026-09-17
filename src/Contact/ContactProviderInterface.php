<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Contact;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Providers do not exclude the viewer, normalise queries or cap configured limits;
 * ContactService owns those concerns. Permissions may be asymmetric:
 * canContact(A, B) need not equal canContact(B, A). Only new conversations check them.
 */
#[AutoconfigureTag('contao_member_chat.contact_provider')]
interface ContactProviderInterface
{
    public static function getAlias(): string;

    /**
     * @return list<Contact>
     */
    public function search(Viewer $viewer, string $query, int $limit): array;

    public function canContact(Viewer $viewer, int $memberId): bool;
}
