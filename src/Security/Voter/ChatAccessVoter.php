<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Security\Voter;

use Contao\FrontendUser;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The only voter on MEMBER_CHAT_ACCESS, so the result does not depend on the
 * access decision strategy. Projects add requirements by decorating this service.
 *
 * @extends Voter<'MEMBER_CHAT_ACCESS', mixed>
 */
final class ChatAccessVoter extends Voter
{
    public const string ACCESS = 'MEMBER_CHAT_ACCESS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCESS;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof FrontendUser && (int) $user->id > 0;
    }
}
