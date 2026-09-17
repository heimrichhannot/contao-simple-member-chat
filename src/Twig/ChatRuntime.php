<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Twig;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationUrlGenerator;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

final readonly class ChatRuntime
{
    public function __construct(
        private FrontendMemberProvider $members,
        private ParticipantGatewayInterface $participants,
        private ConversationUrlGenerator $urls,
        private UrlGeneratorInterface $routes,
        private ChatOptions $options,
        private RequestStack $requests,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    #[AsTwigFunction('member_chat_unread_badge', isSafe: ['html'])]
    public function unreadBadge(Environment $twig, array $attributes = []): string
    {
        // Also mark anonymous renders: a cached anonymous page must not hide a
        // subsequently authenticated viewer's badge. The response listener applies this.
        $request = $this->requests->getMainRequest();
        $request?->attributes->set('_member_chat_badge', true);
        try {
            $memberId = $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return '';
        }

        $locale = $this->requests->getCurrentRequest()?->getLocale() ?? 'en';

        return $twig->render('@Contao/member_chat/unread_badge.html.twig', [
            'attributes' => $attributes,
            'count' => $this->participants->unreadCount($memberId),
            'url' => $this->urls->listPage($memberId),
            'poll_url' => $this->routes->generate('contao_member_chat_unread', [
                '_locale' => $locale,
            ]),
            'language' => $locale,
            'options' => $this->options,
        ]);
    }
}
