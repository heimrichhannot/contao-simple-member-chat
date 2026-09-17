<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContactResolverTest extends TestCase
{
    public function testBatchResolvesIndependentlyAndPreservesRequestedKeys(): void
    {
        $members = $this->createMock(ContactGatewayInterface::class);
        $members->expects(self::once())->method('findMembers')->with([7, 8])->willReturn([[
            'id' => 7,
            'username' => 'Existing',
        ]]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with('MSC.member_chat.deleted_member', [], 'contao_default')->willReturn('Deleted member');
        $resolver = new ContactResolver($members, new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class)), $translator);
        self::assertSame([], $resolver->resolveMany([]));
        $contacts = $resolver->resolveMany([7, 0, 8, 7]);
        self::assertSame([7, 0, 8], array_keys($contacts));
        self::assertSame('Existing', $contacts[7]->displayName);
        self::assertSame(0, $contacts[8]->memberId);
        self::assertSame('Deleted member', $contacts[0]->displayName);
    }

    public function testZeroDoesNotQueryMembers(): void
    {
        $members = $this->createMock(ContactGatewayInterface::class);
        $members->expects(self::never())->method('findMembers');
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Deleted member');
        $resolver = new ContactResolver($members, new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class)), $translator);
        self::assertSame(0, $resolver->resolve(0)->memberId);
        self::assertSame('Deleted member', $resolver->resolve(-1)->displayName);
    }
}
