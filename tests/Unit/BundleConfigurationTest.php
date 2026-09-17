<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\HeimrichHannotSimpleMemberChatBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class BundleConfigurationTest extends TestCase
{
    public function testDefaultsAndOwnCacheBackedLimiter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir() . '/member-chat-container');

        $extension = new HeimrichHannotSimpleMemberChatBundle()->getContainerExtension();
        self::assertNotNull($extension);
        self::assertSame('contao_member_chat', $extension->getAlias());
        $extension->load([], $container);
        $arguments = array_values($container->getDefinition(ChatOptions::class)->getArguments());
        self::assertEquals(new ChatOptions(), new \ReflectionClass(ChatOptions::class)->newInstanceArgs($arguments));
        $limiter = $container->getDefinition('contao_member_chat.rate_limiter');
        self::assertSame(RateLimiterFactory::class, $limiter->getClass());
        self::assertSame([
            'id' => 'contao_member_chat.rate_limiter',
            'policy' => 'sliding_window',
            'limit' => 30,
            'interval' => '1 minute',
        ], $limiter->getArgument(0));
        $storage = $limiter->getArgument(1);
        self::assertInstanceOf(Definition::class, $storage);
        self::assertSame(CacheStorage::class, $storage->getClass());
        $cache = $storage->getArgument(0);
        self::assertInstanceOf(Reference::class, $cache);
        self::assertSame('cache.app', (string) $cache);
    }

    public function testCustomProviderOptionsArePreservedAndExternalLimiterIsAliased(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir() . '/member-chat-container');

        $extension = new HeimrichHannotSimpleMemberChatBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([[
            'providers' => [
                'project' => [
                    'custom' => true,
                ],
            ],
            'message' => [
                'rate_limiter' => 'project_chat',
            ],
            'contact' => [
                'avatar_size' => 12,
            ],
        ]], $container);
        self::assertEquals([
            'member_groups' => [
                'groups' => [],
            ],
            'project' => [
                'custom' => true,
            ],
        ], $container->getParameter('contao_member_chat.providers'));
        self::assertFalse($container->hasDefinition('contao_member_chat.rate_limiter'));
        self::assertSame('limiter.project_chat', (string) $container->getAlias('contao_member_chat.rate_limiter'));
        self::assertSame(12, $container->getDefinition(ChatOptions::class)->getArgument('$avatarSize'));
    }

    public function testInvalidConfigurationIsRejected(): void
    {
        $extension = new HeimrichHannotSimpleMemberChatBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $this->expectException(InvalidConfigurationException::class);
        $extension->load([[
            'message' => [
                'max_length' => 0,
            ],
        ]], new ContainerBuilder());
    }
}
