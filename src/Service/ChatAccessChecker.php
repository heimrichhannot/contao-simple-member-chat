<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use Contao\CoreBundle\Security\User\ContaoUserProvider;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ChatAccessVoter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/** Checks chat access for members other than the current viewer. */
final readonly class ChatAccessChecker
{
    public function __construct(
        #[Autowire(service: 'contao.security.frontend_user_provider')] private ContaoUserProvider $users,
        private UserAuthorizationCheckerInterface $authorization,
    ) {
    }

    public function isGrantedFor(int $memberId): bool
    {
        if ($memberId <= 0) {
            return false;
        }

        try {
            $user = $this->users->loadUserById($memberId);
        } catch (UserNotFoundException) {
            return false;
        }

        return $this->authorization->isGrantedForUser($user, ChatAccessVoter::ACCESS);
    }

    public function isGrantedForPartner(Conversation $conversation, int $viewerId): bool
    {
        return $this->isGrantedFor($conversation->memberLow === $viewerId ? $conversation->memberHigh : $conversation->memberLow);
    }
}
