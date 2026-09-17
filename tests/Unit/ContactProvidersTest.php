<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\MemberGroupsContactProvider;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\SharedGroupsContactProvider;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Viewer;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use PHPUnit\Framework\TestCase;

final class ContactProvidersTest extends TestCase
{
    public function testBothProvidersUseTheirOwnGroupPolicy(): void
    {
        foreach ([false, true] as $shared) {
            $members = $this->createMock(ContactGatewayInterface::class);
            $groups = $shared ? [3] : [2];
            $members->expects(self::once())->method('search')->with($groups, 'An', 4)->willReturn([[
                'id' => 7,
                'firstname' => 'Anna',
            ]]);
            $members->expects(self::once())->method('canContact')->with($groups, 7)->willReturn(true);
            $factory = new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class));
            $provider = $shared ? new SharedGroupsContactProvider($members, $factory, []) : new MemberGroupsContactProvider($members, $factory, [
                'groups' => [2, 2],
            ]);
            $viewer = new Viewer(9, [3]);
            self::assertSame('Anna', $provider->search($viewer, 'An', 4)[0]->displayName);
            self::assertTrue($provider->canContact($viewer, 7));
        }
    }

    public function testInvalidOptionsAreRejected(): void
    {
        $factory = new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class));
        $members = self::createStub(ContactGatewayInterface::class);
        foreach ([[], [
            'groups' => ['2'],
        ], [
            'groups' => [0],
        ], [
            'groups' => [-1],
        ], [
            'groups' => [2],
            'unexpected' => true,
        ]] as $options) {
            try {
                new MemberGroupsContactProvider($members, $factory, $options);
                self::fail('Expected invalid member_groups options.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('member_groups', $exception->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new SharedGroupsContactProvider($members, $factory, [
            'unexpected' => true,
        ]);
    }
}
