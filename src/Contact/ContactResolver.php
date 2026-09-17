<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Contact;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ContactResolver
{
    public function __construct(
        private ContactGatewayInterface $members,
        private ContactFactory $factory,
        private TranslatorInterface $translator,
    ) {
    }

    public function resolve(int $memberId): Contact
    {
        return $this->resolveMany([$memberId])[$memberId];
    }

    /** @param list<int> $memberIds
     * @return array<int, Contact>
     */
    public function resolveMany(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $placeholder = new Contact(0, $this->translator->trans('MSC.member_chat.deleted_member', [], 'contao_default'));
        $contacts = array_fill_keys($memberIds, $placeholder);
        $ids = array_values(array_filter(array_unique($memberIds), static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            foreach ($this->factory->fromMemberRows($this->members->findMembers($ids)) as $contact) {
                $contacts[$contact->memberId] = $contact;
            }
        }

        return $contacts;
    }
}
