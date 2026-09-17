<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\Input;
use Contao\PageModel;
use Contao\TestCase\ContaoTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactFactory;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\ConversationListItem;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\ChatResponseListener;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGateway;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ContactGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ConversationVoter;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationAccess;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationUrlGenerator;
use HeimrichHannot\SimpleMemberChatBundle\Twig\MessageRuntime;
use HeimrichHannot\SimpleMemberChatBundle\View\ChatViewFactory;
use HeimrichHannot\SimpleMemberChatBundle\View\DaySeparatorFactory;
use HeimrichHannot\SimpleMemberChatBundle\View\TurboResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Loader\ArrayLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

final class FrontendTest extends ContaoTestCase
{
    public function testAutolinkEscapesTextAndAttributesWithoutTrustingEntities(): void
    {
        $twig = new Environment(new ArrayLoader([
            'message' => '{{ text|member_chat_autolink|nl2br }}',
        ]), [
            'autoescape' => 'html',
        ]);
        $twig->addExtension(new AttributeExtension(MessageRuntime::class));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            MessageRuntime::class => static fn (): MessageRuntime => new MessageRuntime(),
        ]));

        $html = $twig->render('message', [
            'text' => "<script>alert(1)</script>\nhttps://example.org/?a=1&b='x'\njavascript:alert(1) &quot; https://example.org/\"onclick=\"evil",
        ]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringNotContainsString(' onclick=', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&amp;quot;', $html);
        self::assertStringContainsString('a=1&amp;b=&#039;x&#039;', $html);
        self::assertStringContainsString('rel="noopener nofollow" target="_blank"', $html);
        self::assertStringContainsString('<br />', $html);
        self::assertSame('ftp://example.org javascript:alert(1)', new MessageRuntime()->autolink('ftp://example.org javascript:alert(1)'));
        self::assertStringContainsString('href="http://example.org"', new MessageRuntime()->autolink('http://example.org'));
    }

    public function testResponseRepresentationsArePrivateAndVaryOnAccept(): void
    {
        $factory = new TurboResponseFactory();
        foreach ([$factory->html('bad', 422), $factory->stream('<turbo-stream/>'), $factory->html('', 204)] as $response) {
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame(['Accept'], $response->getVary());
        }

        self::assertSame(422, $factory->html('bad', 422)->getStatusCode());
        self::assertSame('text/vnd.turbo-stream.html; charset=UTF-8', $factory->stream('')->headers->get('Content-Type'));
    }

    public function testKernelErrorsOnChatRoutesCannotBeStored(): void
    {
        $response = new Response('', Response::HTTP_NOT_FOUND);
        $response->setPublic();
        $response->setVary('Cookie');

        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), Request::create('/_member_chat/conversations/invalid/messages'), HttpKernelInterface::MAIN_REQUEST, $response);
        new ChatResponseListener()($event);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame(['Cookie', 'Accept'], $response->getVary());
        $other = new Response('ordinary');
        new ChatResponseListener()(new ResponseEvent(self::createStub(HttpKernelInterface::class), Request::create('/ordinary'), HttpKernelInterface::MAIN_REQUEST, $other));
        self::assertFalse($other->headers->hasCacheControlDirective('no-store'));
    }

    public function testMalformedItemIsConsumedAndRejectedBeforeLookup(): void
    {
        $input = $this->createAdapterMock(['get']);
        $input->expects(self::once())->method('__call')->with('get', ['auto_item'])->willReturn('not-a-uuid');
        $gateway = $this->createMock(ConversationGatewayInterface::class);
        $gateway->expects(self::never())->method('findByUuid');
        $access = new ConversationAccess($gateway, self::createStub(AuthorizationCheckerInterface::class), $this->createContaoFrameworkStub([
            Input::class => $input,
        ]));
        $this->expectException(PageNotFoundException::class);
        $access->fromItem();
    }

    public function testAnonymousItemIsConsumedWithoutLookingUpPrivateData(): void
    {
        $input = $this->createAdapterMock(['get']);
        $input->expects(self::once())->method('__call')->with('get', ['auto_item'])->willReturn('01994daa-1111-7111-8111-111111111111');
        $gateway = $this->createMock(ConversationGatewayInterface::class);
        $gateway->expects(self::never())->method('findByUuid');
        $access = new ConversationAccess($gateway, self::createStub(AuthorizationCheckerInterface::class), $this->createContaoFrameworkStub([
            Input::class => $input,
        ]));
        self::assertNull($access->fromItem(false));
    }

    public function testForeignItemIsNotFound(): void
    {
        $conversation = new Conversation(1, '01994daa-1111-7111-8111-111111111111', 7, 9, 0, 0, 0);
        $input = $this->createAdapterStub(['get']);
        $input->method('__call')->willReturn($conversation->uuid);
        $gateway = self::createStub(ConversationGatewayInterface::class);
        $gateway->method('findByUuid')->willReturn($conversation);
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->expects(self::once())->method('isGranted')->with(ConversationVoter::VIEW, $conversation)->willReturn(false);
        $access = new ConversationAccess($gateway, $authorization, $this->createContaoFrameworkStub([
            Input::class => $input,
        ]));
        $this->expectException(PageNotFoundException::class);
        $access->fromItem();
    }

    public function testMissingItemShowsListAndUnknownUuidIsNotFound(): void
    {
        $input = $this->createAdapterStub(['get']);
        $input->method('__call')->willReturn(null);
        $access = new ConversationAccess(self::createStub(ConversationGatewayInterface::class), self::createStub(AuthorizationCheckerInterface::class), $this->createContaoFrameworkStub([
            Input::class => $input,
        ]));
        self::assertNull($access->fromItem());
        $this->expectException(PageNotFoundException::class);
        $access->requireUuid('01994daa-1111-7111-8111-111111111111');
    }

    public function testPageUrlUsesInheritedPageAndExplicitItemParameter(): void
    {
        $page = $this->createClassWithPropertiesStub(PageModel::class, [
            'id' => 12,
            'type' => 'regular',
        ]);
        $adapter = $this->createAdapterMock(['findWithDetails']);
        $adapter->expects(self::once())->method('__call')->with('findWithDetails', [12])->willReturn($page);
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->expects(self::exactly(2))->method('generate')->willReturnCallback(static function (object $content, array $parameters) use ($page): string {
            self::assertSame($page, $content);
            self::assertContains($parameters['parameters'], ['/uuid', '']);

            return '/chat' . $parameters['parameters'];
        });
        $helper = new ConversationUrlGenerator($this->createContaoFrameworkStub([
            PageModel::class => $adapter,
        ]), $urls, self::createStub(ParticipantGatewayInterface::class));
        self::assertSame($page, $helper->page(12));
        self::assertSame('/chat/uuid', $helper->generate($page, 'uuid'));
        self::assertSame('/chat', $helper->generate($page));
    }

    public function testMissingRedirectPageIsRejected(): void
    {
        $adapter = $this->createAdapterStub(['findWithDetails']);
        $adapter->method('__call')->willReturn(null);
        $helper = new ConversationUrlGenerator($this->createContaoFrameworkStub([
            PageModel::class => $adapter,
        ]), self::createStub(ContentUrlGenerator::class), self::createStub(ParticipantGatewayInterface::class));
        $this->expectException(PageNotFoundException::class);
        $helper->page(0);
    }

    public function testViewResolvesPartnersAndAuthorsInOneBatchAndCarriesReadState(): void
    {
        $members = $this->createMock(ContactGatewayInterface::class);
        $members->expects(self::once())->method('findMembers')->with([7, 9])->willReturn([[
            'id' => 7,
            'username' => 'Partner',
        ], [
            'id' => 9,
            'username' => 'Viewer',
        ]]);
        $resolver = new ContactResolver($members, new ContactFactory(new ChatOptions(), self::createStub(Studio::class), self::createStub(ContaoFramework::class)), self::createStub(TranslatorInterface::class));
        $urls = self::createStub(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('/chat/uuid');
        $factory = new ChatViewFactory($resolver, new ConversationUrlGenerator(self::createStub(ContaoFramework::class), $urls, self::createStub(ParticipantGatewayInterface::class)), new DaySeparatorFactory(self::createStub(TranslatorInterface::class)));
        $conversation = new Conversation(1, 'uuid', 7, 9, 0, 100, 11);
        $view = $factory->create($this->createClassWithPropertiesStub(PageModel::class), 9, [new ConversationListItem($conversation, 7, null, 2, false, 120)], [new Message(10, 1, 9, 'own', 100), new Message(11, 1, 7, 'partner', 100), new Message(12, 1, 7, 'next day', 100000)], 7, 10, moreMessages: true, moreConversations: true, muted: true);
        self::assertSame('Partner', $view->partner?->displayName);
        self::assertSame('Viewer', $view->messages[0]->author->displayName);
        self::assertTrue($view->messages[0]->own);
        self::assertTrue($view->messages[0]->readByPartner);
        self::assertFalse($view->messages[1]->readByPartner);
        self::assertSame(12, $view->lastMessageId);
        self::assertSame(10, $view->beforeMessageId);
        self::assertSame('100,1', $view->beforeConversation);
        self::assertTrue($view->muted);
        self::assertNotNull($view->messages[0]->daySeparator);
        self::assertNull($view->messages[1]->daySeparator);
        self::assertNotNull($view->messages[2]->daySeparator);
        $empty = $factory->create($this->createClassWithPropertiesStub(PageModel::class), 9);
        self::assertNull($empty->beforeMessageId);
        self::assertNull($empty->beforeConversation);
        self::assertFalse($empty->muted);
        self::assertSame(120, $view->changedAt);
        self::assertSame('uuid', $view->conversations[0]->uuid);
    }

    public function testDayLabelsUseCalendarDaysAndPageLocale(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnCallback(static function (string $id, array $parameters, ?string $domain, ?string $locale): string {
            self::assertSame('en', $locale);

            return $id;
        });
        $days = new DaySeparatorFactory($translator);
        $today = new \DateTimeImmutable('2026-09-17 12:00:00');
        self::assertSame('member_chat.today', $days->create($today->getTimestamp(), 'en', 'd/m/Y', $today->getTimestamp())->label);
        self::assertSame('member_chat.yesterday', $days->create($today->modify('-1 day')->getTimestamp(), 'en', 'd/m/Y', $today->getTimestamp())->label);
        self::assertSame('Tuesday', $days->create($today->modify('-2 days')->getTimestamp(), 'en', 'd/m/Y', $today->getTimestamp())->label);
        self::assertSame('10/09/2026', $days->create($today->modify('-7 days')->getTimestamp(), 'en', 'd/m/Y', $today->getTimestamp())->label);
    }

    public function testWordPrefixSqlEscapesEverySearchPosition(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->expects(self::once())->method('iterateAssociative')->willReturnCallback(static function (string $sql, array $parameters): \Traversable {
            self::assertSame(6, substr_count($sql, "LIKE ? ESCAPE '!'"));
            self::assertSame(['Ca!%!_!!%', '% Ca!%!_!!%', 'Ca!%!_!!%', '% Ca!%!_!!%', 'Ca!%!_!!%', '% Ca!%!_!!%'], \array_slice($parameters, 2));

            return new \ArrayIterator([]);
        });
        self::assertSame([], new ContactGateway($connection, new ChatOptions())->search([2], 'Ca%_!', 20));
    }

    public function testAvatarSchemaCheckIsMemoizedIncludingMissingColumn(): void
    {
        foreach ([[], [
            'avatar' => new Column('avatar', Type::getType(Types::BINARY)),
        ]] as $columns) {
            $schema = $this->createMock(AbstractSchemaManager::class);
            $schema->expects(self::once())->method('listTableColumns')->with('tl_member')->willReturn($columns);
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())->method('createSchemaManager')->willReturn($schema);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn (string $column): string => '`' . $column . '`');
            $connection->expects(self::exactly(2))->method('fetchAllAssociative')->willReturn([]);
            $gateway = new ContactGateway($connection, new ChatOptions(avatarField: 'avatar'));
            $gateway->findMembers([7]);
            $gateway->findMembers([9]);
        }
    }
}
