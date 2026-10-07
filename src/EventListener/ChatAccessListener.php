<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\EventListener;

use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ChatAccessVoter;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use HeimrichHannot\SimpleMemberChatBundle\View\TurboResponseFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Guards every chat route, including routes added later. Anonymous requests pass
 * through, so the controllers keep answering them with 401.
 */
final readonly class ChatAccessListener
{
    public function __construct(
        private FrontendMemberProvider $members,
        private AuthorizationCheckerInterface $authorization,
        private TurboResponseFactory $responses,
    ) {
    }

    #[AsEventListener]
    public function __invoke(ControllerEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !str_starts_with($route, 'contao_member_chat_')) {
            return;
        }

        try {
            $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return;
        }

        if (!$this->authorization->isGranted(ChatAccessVoter::ACCESS)) {
            $event->setController(fn (): Response => $this->responses->html('', 403));
        }
    }
}
