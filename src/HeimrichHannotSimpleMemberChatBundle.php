<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationService;
use HeimrichHannot\SimpleMemberChatBundle\Service\MessageService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

class HeimrichHannotSimpleMemberChatBundle extends AbstractBundle
{
    protected string $extensionAlias = 'contao_member_chat';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->stringNode('contact_provider')->defaultValue('member_groups')->cannotBeEmpty()->end()
            ->arrayNode('polling')->addDefaultsIfNotSet()->children()
                ->integerNode('messages_interval')->min(1)->defaultValue(4000)->end()
                ->integerNode('conversations_interval')->min(1)->defaultValue(15000)->end()
                ->integerNode('badge_interval')->min(1)->defaultValue(30000)->end()
                ->integerNode('max_interval_multiplier')->min(1)->defaultValue(8)->end()
            ->end()->end()
            ->arrayNode('message')->addDefaultsIfNotSet()->children()
                ->integerNode('max_length')->min(1)->defaultValue(2000)->end()
                ->integerNode('rate_limit')->min(1)->defaultValue(30)->end()
                ->stringNode('rate_limiter')->defaultNull()->end()
            ->end()->end()
            ->arrayNode('list')->addDefaultsIfNotSet()->children()
                ->integerNode('page_size')->min(1)->defaultValue(50)->end()
                ->integerNode('search_limit')->min(1)->defaultValue(20)->end()
                ->integerNode('search_min_length')->min(1)->defaultValue(2)->end()
            ->end()->end()
            ->arrayNode('contact')->addDefaultsIfNotSet()->children()
                ->stringNode('avatar_field')->defaultNull()->end()
                ->variableNode('avatar_size')->defaultValue([96, 96, 'crop'])
                    ->validate()->ifTrue(static fn (mixed $value): bool => (!\is_int($value) || $value < 0)
                        && !(\is_array($value) && array_is_list($value) && \count($value) === 3
                            && \is_int($value[0]) && $value[0] >= 0 && \is_int($value[1]) && $value[1] >= 0
                            && \is_string($value[2]) && $value[2] !== ''))
                        ->thenInvalid('Expected an image size ID or [width, height, mode].')->end()
                ->end()
            ->end()->end()
            ->arrayNode('providers')->ignoreExtraKeys(false)->addDefaultsIfNotSet()->children()
                ->arrayNode('member_groups')->addDefaultsIfNotSet()->children()
                    ->arrayNode('groups')->integerPrototype()->min(1)->end()->defaultValue([])->end()
                ->end()->end()
            ->end()->end()
        ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
    /**
     * @var array{
     *     contact_provider: string,
     *     polling: array{messages_interval: int, conversations_interval: int, badge_interval: int, max_interval_multiplier: int},
     *     message: array{max_length: int, rate_limit: int, rate_limiter: ?string},
     *     list: array{page_size: int, search_limit: int, search_min_length: int},
     *     contact: array{avatar_field: ?string, avatar_size: int|array{int, int, string}},
     *     providers: array<string, mixed>
     * } $config
     */
        $configurator->import('../config/services.yaml');
        foreach ($config as $name => $value) {
            $container->setParameter('contao_member_chat.' . $name, $value);
        }

        $container->setDefinition(ChatOptions::class, new Definition(ChatOptions::class, [
            '$contactProvider' => $config['contact_provider'],
            '$messagesInterval' => $config['polling']['messages_interval'],
            '$conversationsInterval' => $config['polling']['conversations_interval'],
            '$badgeInterval' => $config['polling']['badge_interval'],
            '$maxIntervalMultiplier' => $config['polling']['max_interval_multiplier'],
            '$maxLength' => $config['message']['max_length'],
            '$rateLimit' => $config['message']['rate_limit'],
            '$rateLimiter' => $config['message']['rate_limiter'],
            '$pageSize' => $config['list']['page_size'],
            '$searchLimit' => $config['list']['search_limit'],
            '$searchMinLength' => $config['list']['search_min_length'],
            '$avatarField' => $config['contact']['avatar_field'],
            '$avatarSize' => $config['contact']['avatar_size'],
            '$providers' => $config['providers'],
        ]));

        $limiterId = 'contao_member_chat.rate_limiter';
        if ($config['message']['rate_limiter'] !== null) {
            $container->setAlias($limiterId, 'limiter.' . $config['message']['rate_limiter']);
        } else {
            $container->setDefinition($limiterId, new Definition(RateLimiterFactory::class, [
                [
                    'id' => $limiterId,
                    'policy' => 'sliding_window',
                    'limit' => $config['message']['rate_limit'],
                    'interval' => '1 minute',
                ],
                new Definition(CacheStorage::class, [new Reference('cache.app')]),
            ]));
        }

        foreach ([MessageService::class, ConversationService::class] as $service) {
            $container->getDefinition($service)->setArgument('$rateLimiter', new Reference($limiterId));
        }
    }
}
