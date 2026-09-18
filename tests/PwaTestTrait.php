<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\Model\Collection;
use Contao\PageModel;
use HeimrichHannot\PwaBundle\Model\PwaConfigurationsModel;
use HeimrichHannot\PwaBundle\Model\PwaPushSubscriberModel;
use HeimrichHannot\PwaBundle\Sender\PushNotificationSender;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\PushOptions;
use HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa\SendChatPushHandler;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationUrlGenerator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

trait PwaTestTrait
{
    /**
     * @param list<PwaPushSubscriberModel>|null $subscribers
     */
    protected function pushHandler(
        ParticipantGatewayInterface $participants,
        PushNotificationSender $sender,
        PushOptions $options = new PushOptions(true, 3, 60, 4),
        ?LoggerInterface $logger = null,
        ?MessageGatewayInterface $messages = null,
        ?ConversationGatewayInterface $conversations = null,
        bool $link = true,
        ?array $subscribers = null,
        string $supportPush = '1',
    ): SendChatPushHandler {
        if (!$messages instanceof MessageGatewayInterface) {
            $messages = self::createStub(MessageGatewayInterface::class);
            $messages->method('find')->willReturn(new Message(42, 1, 7, 'Hello 🌍!', 100));
        }

        if (!$conversations instanceof ConversationGatewayInterface) {
            $conversations = self::createStub(ConversationGatewayInterface::class);
            $conversations->method('find')->willReturn(new Conversation(1, 'uuid', 7, 9, 100, 100, 42));
        }

        $config = $this->createClassWithPropertiesStub(PwaConfigurationsModel::class, [
            'id' => 3,
            'supportPush' => $supportPush,
        ]);
        $configs = $this->createAdapterStub(['findByPk']);
        $configs->method('__call')->willReturn($config);
        $subscriptions = $this->createAdapterStub(['findBy']);
        $subscriptions->method('__call')->willReturnCallback(function (string $method, array $args) use ($subscribers): ?Collection {
            self::assertSame('findBy', $method);
            self::assertSame(['tl_pwa_pushsubscriber.pid=?', 'tl_pwa_pushsubscriber.member=?'], $args[0]);
            self::assertIsArray($args[1]);
            self::assertSame(3, $args[1][0]);
            $targets = $subscribers ?? [
                $this->createClassWithPropertiesStub(PwaPushSubscriberModel::class, [
                    'pid' => 3,
                    'member' => $args[1][1],
                ])];

            return $targets === [] ? null : new Collection($targets, 'tl_pwa_pushsubscriber');
        });
        $pages = $this->createAdapterStub(['findPublishedById', 'findPublishedRootPages']);
        $pages->method('__call')->willReturnCallback(function (string $method, array $args) use ($link): ?PageModel {
            if (!$link || $method !== 'findPublishedById') {
                return null;
            }

            return $this->createClassWithPropertiesStub(PageModel::class, [
                'id' => $args[0],
                'type' => 'regular',
                'isPublic' => true,
                'rootIsPublic' => true,
            ]);
        });
        $framework = $this->createContaoFrameworkStub([
            PwaConfigurationsModel::class => $configs,
            PwaPushSubscriberModel::class => $subscriptions,
            PageModel::class => $pages,
        ]);
        $urls = self::createStub(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(static function (PageModel $page, array $parameters, int $referenceType): string {
            self::assertSame(UrlGeneratorInterface::ABSOLUTE_URL, $referenceType);
            self::assertIsString($parameters['parameters']);

            return 'https://example.org/page' . $page->id . $parameters['parameters'];
        });
        $members = self::createStub(ContactGatewayInterface::class);
        $members->method('findMembers')->willReturn([[
            'id' => 7,
            'firstname' => 'Alice',
            'lastname' => 'Chat',
        ]]);
        $contacts = new ContactResolver($members, new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class)), self::createStub(TranslatorInterface::class));

        return new SendChatPushHandler($messages, $conversations, $participants, $contacts, new ConversationUrlGenerator($framework, $urls, $participants), $framework, $sender, $options, $logger ?? new NullLogger());
    }
}
