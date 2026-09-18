<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle;

use HeimrichHannot\PwaBundle\Model\PwaConfigurationsModel;
use HeimrichHannot\PwaBundle\Model\PwaPushSubscriberModel;
use HeimrichHannot\PwaBundle\Notification\DefaultNotification;
use HeimrichHannot\PwaBundle\Sender\PushNotificationSender;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\MemberGroupsContactProvider;
use HeimrichHannot\SimpleMemberChatBundle\Contact\Provider\SharedGroupsContactProvider;
use HeimrichHannot\SimpleMemberChatBundle\DependencyInjection\Compiler\ContactProviderPass;
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

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ContactProviderPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->stringNode('contact_provider')->defaultValue('member_groups')->cannotBeEmpty()->end()
            ->arrayNode('polling')->addDefaultsIfNotSet()->children()
                ->integerNode('activity_throttle')->min(0)->defaultValue(30)->end()
                ->integerNode('messages_interval')->min(1)->defaultValue(4000)->end()
                ->integerNode('conversations_interval')->min(1)->defaultValue(15000)->end()
                ->integerNode('badge_interval')->min(1)->defaultValue(30000)->end()
                ->integerNode('max_interval_multiplier')->min(1)->defaultValue(8)->end()
            ->end()->end()
            ->arrayNode('push')->addDefaultsIfNotSet()->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->integerNode('configuration')->min(0)->defaultValue(0)->end()
                ->integerNode('active_recipient_grace')->min(0)->defaultValue(60)->end()
                ->integerNode('body_length')->min(0)->max(500)->defaultValue(0)->end()
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

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        /**
         * @var array{
         *     contact_provider: string,
         *     polling: array{activity_throttle: int, messages_interval: int, conversations_interval: int, badge_interval: int, max_interval_multiplier: int},
         *     message: array{max_length: int, rate_limit: int, rate_limiter: ?string},
         *     list: array{page_size: int, search_limit: int, search_min_length: int},
         *     contact: array{avatar_field: ?string, avatar_size: int|array{int, int, string}},
         *     push: array{enabled: bool, configuration: int, active_recipient_grace: int, body_length: int},
         *     providers: array<string, mixed>
         * } $options
         */
        $options = $config;
        $configurator->import('../config/services.yaml');
        // Strings here keep optional PWA types out of the always-loaded service graph.
        if (class_exists(PushNotificationSender::class)
            && class_exists(DefaultNotification::class)
            && class_exists(PwaConfigurationsModel::class)
            && class_exists(PwaPushSubscriberModel::class)) {
            $configurator->import('../config/pwa.yaml');
        }

        foreach ($options as $name => $value) {
            $container->setParameter('contao_member_chat.' . $name, $value);
        }

        foreach ($options['push'] as $name => $value) {
            $container->setParameter('contao_member_chat.push.' . $name, $value);
        }

        $container->setDefinition(ChatOptions::class, new Definition(ChatOptions::class, [
            '$contactProvider' => $options['contact_provider'],
            '$messagesInterval' => $options['polling']['messages_interval'],
            '$conversationsInterval' => $options['polling']['conversations_interval'],
            '$badgeInterval' => $options['polling']['badge_interval'],
            '$maxIntervalMultiplier' => $options['polling']['max_interval_multiplier'],
            '$maxLength' => $options['message']['max_length'],
            '$rateLimit' => $options['message']['rate_limit'],
            '$rateLimiter' => $options['message']['rate_limiter'],
            '$pageSize' => $options['list']['page_size'],
            '$searchLimit' => $options['list']['search_limit'],
            '$searchMinLength' => $options['list']['search_min_length'],
            '$avatarField' => $options['contact']['avatar_field'],
            '$avatarSize' => $options['contact']['avatar_size'],
            '$providers' => $options['providers'],
            '$activityThrottle' => $options['polling']['activity_throttle'],
        ]));

        foreach ([MemberGroupsContactProvider::class, SharedGroupsContactProvider::class] as $provider) {
            $container->getDefinition($provider)->setArgument('$options', $options['providers'][$provider::getAlias()] ?? []);
        }

        $limiterId = 'contao_member_chat.rate_limiter';
        if ($options['message']['rate_limiter'] !== null) {
            $container->setAlias($limiterId, 'limiter.' . $options['message']['rate_limiter']);
        } else {
            $container->setDefinition($limiterId, new Definition(RateLimiterFactory::class, [
                [
                    'id' => $limiterId,
                    'policy' => 'sliding_window',
                    'limit' => $options['message']['rate_limit'],
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
