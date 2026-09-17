<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Tests\Unit;

use Contao\BackendUser;
use Contao\FrontendUser;
use Contao\TestCase\ContaoTestCase;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ConversationVoter;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ConversationVoterTest extends ContaoTestCase
{
    public function testOnlyFrontendParticipantsCanView(): void
    {
        $conversation = new Conversation(1, '01994daa-1111-7111-8111-111111111111', 7, 9, 0, 0, 0);
        $voter = new ConversationVoter();
        foreach ([7, 9, 10, 0] as $id) {
            $user = $this->createClassWithPropertiesStub(FrontendUser::class, [
                'id' => $id,
            ]);
            $token = new UsernamePasswordToken($user, 'contao_frontend', ['ROLE_MEMBER']);
            self::assertSame(\in_array($id, [7, 9], true) ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $voter->vote($token, $conversation, [ConversationVoter::VIEW]));
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, 1, [ConversationVoter::VIEW]));
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $conversation, ['OTHER']));
        }

        $backend = $this->createClassWithPropertiesStub(BackendUser::class, [
            'id' => 7,
        ]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken($backend, 'contao_backend', []), $conversation, [ConversationVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $conversation, [ConversationVoter::VIEW]));
    }
}
