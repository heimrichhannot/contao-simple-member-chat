<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Contact;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;
use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ContactService
{
    /**
     * @var \WeakMap<Request, array<int, Viewer>>
     */
    private \WeakMap $viewers;

    public function __construct(
        private readonly ContactProviderRegistry $registry,
        private readonly ContactGatewayInterface $members,
        private readonly FrontendMemberProvider $identity,
        private readonly ChatOptions $options,
        private readonly RequestStack $requests,
    ) {
        $this->viewers = new \WeakMap();
    }

    public function viewer(): Viewer
    {
        $id = $this->identity->requireMemberId();
        $request = $this->requests->getMainRequest();
        if ($request instanceof Request && isset($this->viewers[$request][$id])) {
            return $this->viewers[$request][$id];
        }

        $groups = $this->members->activeGroupIds($id);
        if ($groups === null) {
            throw new AuthenticationRequiredException();
        }

        $viewer = new Viewer($id, $groups);
        if ($request instanceof Request) {
            $cached = $this->viewers[$request] ?? [];
            $cached[$id] = $viewer;
            $this->viewers[$request] = $cached;
        }

        return $viewer;
    }

    /**
     * @return list<Contact>
     */
    public function search(string $query, ?int $limit = null): array
    {
        $viewer = $this->viewer();
        $query = trim($query);
        $limit = min($limit ?? $this->options->searchLimit, $this->options->searchLimit);
        if ($limit <= 0 || mb_strlen($query) < $this->options->searchMinLength) {
            return [];
        }

        $contacts = [];
        foreach ($this->registry->get()->search($viewer, $query, $limit) as $contact) {
            if ($contact->memberId <= 0 || $contact->memberId === $viewer->memberId || isset($contacts[$contact->memberId])) {
                continue;
            }

            $contacts[$contact->memberId] = $contact;
            if (\count($contacts) >= $limit) {
                break;
            }
        }

        return array_values($contacts);
    }

    public function canContact(int $memberId): bool
    {
        $viewer = $this->viewer();

        return $memberId > 0 && $memberId !== $viewer->memberId && $this->registry->get()->canContact($viewer, $memberId);
    }
}
