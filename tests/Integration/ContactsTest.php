<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Integration;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderInterface;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderRegistry;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactService;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\MemberGroupsContactProvider;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\SharedGroupsContactProvider;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Viewer;
use HeimrichHannot\SimpleMemberChatBundle\Event\ConversationCreatedEvent;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGateway;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatEventDispatcher;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatTransaction;
use HeimrichHannot\SimpleMemberChatBundle\Service\ContactPermission;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationService;
use HeimrichHannot\SimpleMemberChatBundle\Tests\DatabaseTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContactsTest extends DatabaseTestCase
{
    private ContactGateway $members;

    private ContactFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->members = new ContactGateway($this->connection, new ChatOptions());
        $this->factory = new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class));
    }

    public function testBothProvidersFilterStatusGroupsAndLiteralPrefixesBeforeLimit(): void
    {
        $this->member(1, [
            'groups' => serialize(['12']),
            'lastname' => 'A Wrong group',
        ]);
        $this->member(2, [
            'disable' => 1,
        ]);
        $this->member(3, [
            'login' => 0,
        ]);
        $this->member(4, [
            'start' => (string) (time() + 3600),
        ]);
        $this->member(5, [
            'stop' => (string) (time() - 3600),
        ]);
        $this->member(6, [
            'firstname' => 'Other',
            'lastname' => 'An Example',
            'groups' => serialize([2]),
        ]);
        $this->member(7, [
            'firstname' => 'Other',
            'username' => 'AnnaLogin',
        ]);
        $this->member(8, [
            'firstname' => 'Anna',
            'start' => (string) (time() - 3600),
            'stop' => (string) (time() + 3600),
        ]);
        $this->member(9, [
            'firstname' => 'Joanna',
        ]);
        $this->member(10, [
            'firstname' => 'An%literal',
        ]);
        $this->member(11, [
            'firstname' => 'An_literal',
        ]);
        $this->member(12, [
            'firstname' => 'An!literal',
        ]);
        $this->member(13, [
            'groups' => 'not serialized',
        ]);
        $this->member(14, [
            'groups' => serialize(['2invalid']),
        ]);
        foreach ($this->providers() as $provider) {
            $viewer = new Viewer(99, [2]);
            self::assertSame([12, 10, 8, 11, 7, 6], array_column($provider->search($viewer, 'An', 20), 'memberId'));
            self::assertSame([12], array_column($provider->search($viewer, 'An', 1), 'memberId'));
            foreach ([1, 2, 3, 4, 5, 13, 14, 999, 0] as $denied) {
                self::assertFalse($provider->canContact($viewer, $denied), 'Denied member ' . $denied);
            }

            self::assertTrue($provider->canContact($viewer, 8));
            self::assertSame([10], array_column($provider->search($viewer, 'An%', 20), 'memberId'));
            self::assertSame([11], array_column($provider->search($viewer, 'An_', 20), 'memberId'));
            self::assertSame([12], array_column($provider->search($viewer, 'An!', 20), 'memberId'));
            self::assertSame([], $provider->search($viewer, 'An', 0));
        }

        self::assertSame([], new MemberGroupsContactProvider($this->members, $this->factory, [
            'groups' => [],
        ])->search(new Viewer(99, [2]), 'An', 20));
        self::assertFalse(new SharedGroupsContactProvider($this->members, $this->factory, [])->canContact(new Viewer(99, []), 8));
    }

    public function testMemberTimeBoundaries(): void
    {
        $now = time();
        $this->member(1, [
            'start' => (string) $now,
        ]);
        $this->member(2, [
            'stop' => (string) $now,
        ]);
        $this->member(3, [
            'start' => '999999999',
        ]);
        foreach ($this->providers() as $provider) {
            self::assertTrue($provider->canContact(new Viewer(99, [2]), 1));
            self::assertFalse($provider->canContact(new Viewer(99, [2]), 2));
            self::assertTrue($provider->canContact(new Viewer(99, [2]), 3));
        }
    }

    public function testMemberGroupsPermissionIsAsymmetric(): void
    {
        $this->member(1, [
            'groups' => serialize(['3']),
        ]);
        $this->member(2);
        $provider = new MemberGroupsContactProvider($this->members, $this->factory, [
            'groups' => [2],
        ]);
        self::assertTrue($provider->canContact(new Viewer(1, [3]), 2));
        self::assertFalse($provider->canContact(new Viewer(2, [2]), 1));
    }

    public function testActiveViewerGroupsAndSharedPermission(): void
    {
        $now = time();
        foreach ([[
            'id' => 2,
        ], [
            'id' => 3,
            'disable' => 1,
        ], [
            'id' => 4,
            'start' => (string) ($now + 3600),
        ], [
            'id' => 5,
            'stop' => (string) ($now - 3600),
        ], [
            'id' => 6,
            'start' => (string) ($now - 3600),
            'stop' => (string) ($now + 3600),
        ]] as $row) {
            $this->connection->insert('tl_member_group', $row);
        }

        $this->member(1, [
            'groups' => serialize(['2', 2, '3', '4', '5', 6, '999']),
        ]);
        $this->member(2, [
            'groups' => serialize(['3']),
        ]);
        self::assertSame([2, 6], $this->members->activeGroupIds(1));
        self::assertSame([], $this->members->activeGroupIds(2));
        self::assertNull($this->members->activeGroupIds(999));
        $provider = new SharedGroupsContactProvider($this->members, $this->factory, []);
        self::assertFalse($provider->canContact(new Viewer(1, [2, 6]), 2));
        $this->member(3, [
            'disable' => 1,
        ]);
        // Target activity may also make shared-group permission asymmetric.
        self::assertTrue($provider->canContact(new Viewer(3, [2]), 1));
        self::assertFalse($provider->canContact(new Viewer(1, [2, 6]), 3));
    }

    public function testBatchDisplayIncludesInactiveMembersAndMissingAvatarField(): void
    {
        $this->member(7, [
            'disable' => 1,
            'login' => 0,
        ]);
        $gateway = new ContactGateway($this->connection, new ChatOptions(avatarField: 'removed_field'));
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Deleted member');
        $resolver = new ContactResolver($gateway, $this->factory, $translator);
        $contacts = $resolver->resolveMany([7, 8, 0]);
        self::assertSame('Anna', $contacts[7]->displayName);
        self::assertNull($contacts[7]->avatarUrl);
        self::assertSame(0, $contacts[8]->memberId);
        self::assertSame('Deleted member', $contacts[0]->displayName);
        $rows = new ContactGateway($this->connection, new ChatOptions(avatarField: 'avatar'))->findMembers([7]);
        self::assertArrayHasKey('avatar', $rows[0]);
        self::assertArrayNotHasKey('groups', $rows[0]);
        self::assertArrayNotHasKey('login', $rows[0]);
    }

    public function testPermittedContactCreatesConversationThroughRealAdapter(): void
    {
        $this->member(7);
        $this->member(9, [
            'groups' => serialize(['3']),
        ]);
        $options = new ChatOptions();
        $requests = new RequestStack([new Request()]);
        $contacts = new ContactService(new ContactProviderRegistry([
            'member_groups' => new MemberGroupsContactProvider($this->members, $this->factory, [
                'groups' => [2],
            ]),
        ], $options), $this->members, $this->memberProvider(9), $options, $requests);
        $participants = new ParticipantGateway($this->connection);
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(ConversationCreatedEvent::class, function (ConversationCreatedEvent $event) use (&$events): void {
            self::assertFalse($this->connection->isTransactionActive());
            $events[] = $event;
        });
        $service = new ConversationService(new ConversationGateway($this->connection), $participants, new ContactPermission($contacts), $this->memberProvider(9), new RateLimiterFactory([
            'id' => 'contacts',
            'policy' => 'sliding_window',
            'limit' => 1,
            'interval' => '1 minute',
        ], new InMemoryStorage()), new ChatTransaction($this->connection), new ChatEventDispatcher($dispatcher, new NullLogger()));
        $conversation = $service->openWith(9, 7);
        self::assertSame([7, 9], $participants->memberIds($conversation->id));
        self::assertCount(1, $events);
        $this->connection->update('tl_member', [
            'disable' => 1,
        ], [
            'id' => 7,
        ]);
        self::assertFalse($contacts->canContact(7));
        self::assertSame($conversation->id, $service->openWith(9, 7)->id);
        self::assertCount(1, $events);
    }

    /**
     * @param array<string, int|string> $overrides
     */
    private function member(int $id, array $overrides = []): void
    {
        $this->connection->insert('tl_member', array_replace([
            'id' => $id,
            'firstname' => 'Anna',
            'username' => 'member' . $id,
            'groups' => serialize(['2']),
            'login' => 1,
        ], $overrides));
    }

    /**
     * @return list<ContactProviderInterface>
     */
    private function providers(): array
    {
        return [new MemberGroupsContactProvider($this->members, $this->factory, [
            'groups' => [2],
        ]), new SharedGroupsContactProvider($this->members, $this->factory, [])];
    }
}
