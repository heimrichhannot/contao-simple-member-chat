<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderInterface;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactProviderRegistry;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\MemberGroupsContactProvider;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\SharedGroupsContactProvider;
use HeimrichHannot\SimpleMemberChatBundle\DependencyInjection\Compiler\ContactProviderPass;
use HeimrichHannot\SimpleMemberChatBundle\HeimrichHannotSimpleMemberChatBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ContactProviderRegistryTest extends TestCase
{
    public function testConfiguredAliasIsSelected(): void
    {
        $first = self::createStub(ContactProviderInterface::class);
        $second = self::createStub(ContactProviderInterface::class);
        $registry = new ContactProviderRegistry([
            'member_groups' => $first,
            'shared_groups' => $second,
        ], new ChatOptions(contactProvider: 'shared_groups'));
        self::assertSame($second, $registry->get());
    }

    public function testUnknownAliasFailsOnDirectUse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown contact provider "missing"');
        new ContactProviderRegistry([], new ChatOptions(contactProvider: 'missing'))->get();
    }

    public function testCompilerPassAcceptsBothAliasesAndRejectsUnknown(): void
    {
        $container = new ContainerBuilder();
        foreach ([MemberGroupsContactProvider::class, SharedGroupsContactProvider::class] as $class) {
            $container->register($class, $class)->addTag('contao_member_chat.contact_provider');
        }

        $pass = new ContactProviderPass();
        foreach (['member_groups', 'shared_groups'] as $alias) {
            $container->setParameter('contao_member_chat.contact_provider', $alias);
            $pass->process($container);
            self::assertTrue($container->hasDefinition(MemberGroupsContactProvider::class));
        }

        $container->setParameter('contao_member_chat.contact_provider', 'missing');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Available providers: member_groups, shared_groups');
        $pass->process($container);
    }

    public function testRealCompilationRejectsUnknownAliasAfterAutoconfiguration(): void
    {
        $container = new ContainerBuilder();
        $bundle = new HeimrichHannotSimpleMemberChatBundle();
        $bundle->build($container);

        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir() . '/member-chat-container');

        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([[
            'contact_provider' => 'missing',
        ]], $container);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Available providers: member_groups, shared_groups');
        $container->compile(true);
    }

    public function testDuplicateAliasesAreRejected(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('contao_member_chat.contact_provider', 'member_groups');
        foreach (['one', 'two'] as $id) {
            $container->register($id, MemberGroupsContactProvider::class)->addTag('contao_member_chat.contact_provider');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate contact provider alias');
        new ContactProviderPass()->process($container);
    }
}
