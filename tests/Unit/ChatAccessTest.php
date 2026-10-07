<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\BackendUser;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\FrontendUser;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\EventListener\ChatAccessListener;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ChatAccessVoter;
use HeimrichHannot\SimpleMemberChatBundle\Tests\ServiceTestCase;
use HeimrichHannot\SimpleMemberChatBundle\View\TurboResponseFactory;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Strategy\PriorityStrategy;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Runtime\EscaperRuntime;
use Twig\TwigFunction;

final class ChatAccessTest extends ServiceTestCase
{
    public function testOnlyFrontendMembersHaveAccess(): void
    {
        $voter = new ChatAccessVoter();
        foreach ([
            7 => VoterInterface::ACCESS_GRANTED,
            0 => VoterInterface::ACCESS_DENIED,
        ] as $id => $expected) {
            $user = $this->createClassWithPropertiesStub(FrontendUser::class, [
                'id' => $id,
            ]);
            $token = new UsernamePasswordToken($user, 'contao_frontend', ['ROLE_MEMBER']);
            self::assertSame($expected, $voter->vote($token, null, [ChatAccessVoter::ACCESS]));
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['OTHER']));
        }

        $backend = $this->createClassWithPropertiesStub(BackendUser::class, [
            'id' => 7,
        ]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken($backend, 'contao_backend', []), null, [ChatAccessVoter::ACCESS]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, [ChatAccessVoter::ACCESS]));
    }

    public function testDecoratedVoterDecidesRegardlessOfStrategy(): void
    {
        $user = $this->createClassWithPropertiesStub(FrontendUser::class, [
            'id' => 7,
        ]);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'contao_frontend', ['ROLE_MEMBER']));
        foreach ([null, new PriorityStrategy(), new UnanimousStrategy()] as $strategy) {
            foreach ([[[], true], [[7], false]] as [$denied, $expected]) {
                $checker = new AuthorizationChecker($storage, new AccessDecisionManager([$this->denyingVoter($denied)], $strategy));
                $decision = new AccessDecision();
                self::assertSame($expected, $checker->isGranted(ChatAccessVoter::ACCESS, null, $decision));
                self::assertSame($expected, $decision->isGranted);
            }
        }
    }

    public function testOtherMembersAreCheckedThroughTheVoter(): void
    {
        $access = $this->chatAccess([7]);
        self::assertTrue($access->isGrantedFor(9));
        self::assertFalse($access->isGrantedFor(7));
        self::assertFalse($access->isGrantedFor(404));
        self::assertFalse($access->isGrantedFor(0));

        $conversation = new Conversation(1, '01994daa-1111-7111-8111-111111111111', 7, 9, 0, 0, 0);
        self::assertFalse($access->isGrantedForPartner($conversation, 9));
        self::assertTrue($access->isGrantedForPartner($conversation, 7));
    }

    public function testChatRoutesAnswerDeniedMembersWithForbidden(): void
    {
        foreach ([
            ['contao_member_chat_messages', 7, false, 403],
            ['contao_member_chat_unread', 7, false, 403],
            ['contao_member_chat_messages', 7, true, null],
            ['contao_member_chat_messages', 0, false, null],
            ['contao_frontend_fragment', 7, false, null],
        ] as [$route, $memberId, $granted, $status]) {
            $authorization = $this->createMock(AuthorizationCheckerInterface::class);
            $authorization->expects(str_starts_with($route, 'contao_member_chat_') && $memberId > 0 ? self::once() : self::never())->method('isGranted')->with(ChatAccessVoter::ACCESS)->willReturn($granted);
            $request = Request::create('/_member_chat/test');
            $request->attributes->set('_route', $route);
            $controller = static fn (): Response => new Response('chat');
            $event = new ControllerEvent(self::createStub(HttpKernelInterface::class), $controller, $request, HttpKernelInterface::MAIN_REQUEST);
            new ChatAccessListener($this->memberProvider($memberId), $authorization, new TurboResponseFactory())($event);
            $response = ($event->getController())();
            self::assertInstanceOf(Response::class, $response);
            self::assertSame($status ?? 200, $response->getStatusCode());
            self::assertSame($status === null ? 'chat' : '', $response->getContent());
        }
    }

    public function testComposeFrameReplacesTheFormWhenThePartnerIsUnavailable(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../contao/templates', 'Contao');
        $twig = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);
        $twig->getRuntime(EscaperRuntime::class)->addSafeClass(HtmlAttributes::class, ['html']);
        $twig->addFunction(new TwigFunction('attrs', static fn (): HtmlAttributes => new HtmlAttributes()));
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));
        $context = [
            'send_url' => '/send',
            'request_token' => 'token',
            'page_id' => 1,
            'error' => null,
            'text' => '',
            'options' => new ChatOptions(),
        ];

        $available = $twig->render('@Contao/member_chat/compose_form.html.twig', $context);
        self::assertStringContainsString('<form action="/send"', $available);
        self::assertStringNotContainsString('member_chat.partner_unavailable', $available);

        $unavailable = $twig->render('@Contao/member_chat/compose_form.html.twig', $context + [
            'partner_access' => false,
        ]);
        self::assertStringNotContainsString('<form', $unavailable);
        self::assertStringContainsString('<p class="member-chat__partner-unavailable">member_chat.partner_unavailable</p>', $unavailable);
        self::assertStringContainsString('id="chat-compose"', $unavailable);
    }
}
