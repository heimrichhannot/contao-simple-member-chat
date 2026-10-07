<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests;

use Contao\CoreBundle\Security\User\ContaoUserProvider;
use Contao\FrontendUser;
use Contao\TestCase\ContaoTestCase;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ChatAccessVoter;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatAccessChecker;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

abstract class ServiceTestCase extends ContaoTestCase
{
    protected function memberProvider(int $id): FrontendMemberProvider
    {
        $user = $this->createClassWithPropertiesStub(FrontendUser::class, [
            'id' => $id,
        ]);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'contao_frontend', ['ROLE_MEMBER']));

        return new FrontendMemberProvider($storage);
    }

    /**
     * Runs through the real ChatAccessVoter, decorated like a project would to deny some members.
     *
     * @param list<int> $denied
     */
    protected function chatAccess(array $denied = []): ChatAccessChecker
    {
        $users = self::createStub(ContaoUserProvider::class);
        $users->method('loadUserById')->willReturnCallback(function (int $id): FrontendUser {
            if ($id === 404) {
                throw new UserNotFoundException();
            }

            return $this->createClassWithPropertiesStub(FrontendUser::class, [
                'id' => $id,
            ]);
        });

        return new ChatAccessChecker($users, new AuthorizationChecker(new TokenStorage(), new AccessDecisionManager([$this->denyingVoter($denied)])));
    }

    /**
     * @param list<int> $denied
     */
    protected function denyingVoter(array $denied): VoterInterface
    {
        return new readonly class(new ChatAccessVoter(), $denied) implements VoterInterface {
            /**
             * @param list<int> $denied
             */
            public function __construct(
                private ChatAccessVoter $inner,
                private array $denied,
            ) {
            }

            public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
            {
                $result = $this->inner->vote($token, $subject, $attributes, $vote);
                $user = $token->getUser();

                return $result === self::ACCESS_GRANTED && $user instanceof FrontendUser && \in_array((int) $user->id, $this->denied, true) ? self::ACCESS_DENIED : $result;
            }
        };
    }
}
