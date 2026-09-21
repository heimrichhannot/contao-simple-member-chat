<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit\Pwa;

use Contao\CoreBundle\Framework\ContaoFramework;
use HeimrichHannot\PwaBundle\Sender\PushNotificationSender;
use HeimrichHannot\SimpleMemberChatBundle\HeimrichHannotSimpleMemberChatBundle;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\MessageSentListener;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\PushOptions;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\SendChatPushHandler;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;

final class PwaContainerTest extends TestCase
{
    public function testPresentClassesRegisterCompilableServicesAndOptions(): void
    {
        $container = $this->container();
        foreach ([MessageSentListener::class, SendChatPushHandler::class, PushOptions::class] as $class) {
            self::assertTrue($container->hasDefinition($class));
            $container->getDefinition($class)->setPublic(true);
        }

        $container->compile(true);
        self::assertTrue($container->has(MessageSentListener::class));
        self::assertTrue($container->has(SendChatPushHandler::class));
        self::assertEquals(new PushOptions(), $container->get(PushOptions::class));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAbsentPwaClassesNeverLoadIntegrationAndContainerCompiles(): void
    {
        $loaders = spl_autoload_functions();
        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }

        spl_autoload_register(static function (string $class) use ($loaders): void {
            if (str_starts_with($class, 'HeimrichHannot\\PwaBundle\\')) {
                return;
            }

            if (str_starts_with($class, 'HeimrichHannot\\SimpleMemberChatBundle\\Integration\\')) {
                throw new \LogicException('Optional integration must not load without PWA.');
            }

            foreach ($loaders as $loader) {
                $loader($class);
            }
        });
        self::assertFalse(class_exists(PushNotificationSender::class));
        $container = $this->container(false);
        foreach (array_keys($container->getDefinitions()) as $id) {
            self::assertStringNotContainsString('Integration\\Pwa', $id);
        }

        $container->compile(true);
        self::assertFalse($container->has(MessageSentListener::class));
        self::assertFalse($container->has(SendChatPushHandler::class));
    }

    public function testCustomConfigurationReachesCompiledOptions(): void
    {
        $container = $this->container(config: [
            'push' => [
                'enabled' => true,
                'configuration' => 3,
                'active_recipient_grace' => 0,
                'body_length' => 120,
            ],
        ]);
        $container->getDefinition(PushOptions::class)->setPublic(true);
        $container->compile(true);
        self::assertEquals(new PushOptions(true, 3, 0, 120), $container->get(PushOptions::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(bool $pwa = true, array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir() . '/member-chat-pwa-container');

        $extension = new HeimrichHannotSimpleMemberChatBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);
        // Isolate the optional graph; the existing chat and host services are external dependencies here.
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!str_contains($id, 'Integration\\Pwa')) {
                $definition->setSynthetic(true)->setPublic(true)->setAutowired(false)->setAutoconfigured(false)->setArguments([]);
            }
        }

        foreach ([ContaoFramework::class, MessageBusInterface::class, LoggerInterface::class, RequestStack::class, ...($pwa ? [PushNotificationSender::class] : [])] as $class) {
            $container->register($class, $class)->setSynthetic(true)->setPublic(true);
        }

        return $container;
    }
}
