<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Controller;

use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use HeimrichHannot\SimpleMemberChatBundle\Twig\ChatRuntime;
use HeimrichHannot\SimpleMemberChatBundle\View\TurboResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

final readonly class UnreadBadgeController
{
    public function __construct(
        private FrontendMemberProvider $members,
        private ChatRuntime $badge,
        private Environment $twig,
        private TurboResponseFactory $responses,
        private LocaleSwitcher $locales,
    ) {
    }

    #[Route('/_member_chat/unread', name: 'contao_member_chat_unread', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        try {
            $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return $this->responses->html('', 401);
        }

        $locale = $request->query->getString('_locale', $request->getLocale());
        if (preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $locale) === 1) {
            $request->setLocale($locale);
            $this->locales->setLocale($locale);
        }

        return $this->responses->html($this->badge->unreadBadge($this->twig));
    }
}
