<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\PageModel;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationUrlGenerator;
use HeimrichHannot\SimpleMemberChatBundle\Tests\ServiceTestCase;
use HeimrichHannot\SimpleMemberChatBundle\Twig\ChatRuntime;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AttributeExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Runtime\EscaperRuntime;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

final class UnreadBadgeTest extends ServiceTestCase
{
    public function testBadgeRendersZeroPositiveAndAnonymousWithoutLosingPolling(): void
    {
        foreach ([0, 3, -1] as $count) {
            $participants = $this->createMock(ParticipantGatewayInterface::class);
            $participants->expects($count < 0 ? self::never() : self::once())->method('unreadCount')->willReturn(max(0, $count));
            $pages = $this->createAdapterStub(['findPublishedRootPages']);
            $pages->method('__call')->willReturn(null);
            $urls = new ConversationUrlGenerator($this->createContaoFrameworkStub([
                PageModel::class => $pages,
            ]), self::createStub(ContentUrlGenerator::class), $participants);
            $routes = self::createStub(UrlGeneratorInterface::class);
            $routes->method('generate')->willReturn('/_member_chat/unread?_locale=en');
            $request = Request::create('/ordinary-page');
            $request->setLocale('en');
            $requests = new RequestStack([$request]);
            $runtime = new ChatRuntime($this->memberProvider($count < 0 ? 0 : 7), $participants, $urls, $routes, new ChatOptions(), $requests);
            $loader = new FilesystemLoader();
            $loader->addPath(__DIR__ . '/../../contao/templates', 'Contao');
            $twig = new Environment($loader, [
                'autoescape' => 'html',
            ]);
            $twig->getRuntime(EscaperRuntime::class)->addSafeClass(HtmlAttributes::class, ['html']);
            $twig->addFunction(new TwigFunction('attrs', static fn (): HtmlAttributes => new HtmlAttributes()));
            $translator = self::createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturn('3 unread messages');
            $twig->addExtension(new TranslationExtension($translator));
            $twig->addExtension(new AttributeExtension(ChatRuntime::class));
            $twig->addRuntimeLoader(new FactoryRuntimeLoader([
                ChatRuntime::class => static fn (): ChatRuntime => $runtime,
            ]));
            $html = $twig->createTemplate('{{ member_chat_unread_badge({class: \'nav"badge\'}) }}')->render();
            self::assertTrue($request->attributes->getBoolean('_member_chat_badge'));
            if ($count < 0) {
                self::assertSame('', $html);
                continue;
            }

            self::assertStringContainsString('id="chat-unread"', $html);
            self::assertStringContainsString('class="nav&quot;badge"', $html);
            self::assertStringContainsString('data-chat-mode="full"', $html);
            self::assertStringContainsString('data-chat-url="/_member_chat/unread?_locale=en"', $html);
            self::assertStringContainsString('aria-live="off"', $html);
            self::assertStringNotContainsString(' src=', $html);
            if ($count === 0) {
                self::assertMatchesRegularExpression('/<turbo-frame[^>]+>\s*<\/turbo-frame>/', $html);
            } else {
                self::assertStringContainsString('<span aria-label="3 unread messages">3</span>', $html);
            }
        }
    }
}
