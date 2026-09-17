<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Security\Voter;

use Contao\FrontendUser;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<'MEMBER_CHAT_VIEW', Conversation> */
final class ConversationVoter extends Voter
{
    public const string VIEW = 'MEMBER_CHAT_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof Conversation;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof FrontendUser && (int) $user->id > 0
            && \in_array((int) $user->id, [$subject->memberLow, $subject->memberHigh], true);
    }
}
