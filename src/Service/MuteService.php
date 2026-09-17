<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Exception\ChatException;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;

final readonly class MuteService
{
    public function __construct(
        private ConversationGatewayInterface $conversations,
        private ParticipantGatewayInterface $participants,
        private FrontendMemberProvider $members,
        private ChatTransaction $transaction,
    ) {
    }

    public function setMuted(int $conversationId, int $memberId, bool $muted): void
    {
        if ($this->members->requireMemberId() !== $memberId) {
            throw new ChatException('member_chat.not_found', 404);
        }

        $this->transaction->run(function () use ($conversationId, $memberId, $muted): void {
            if (!$this->conversations->find($conversationId, true) instanceof Conversation || $this->participants->state($conversationId, $memberId) === null) {
                throw new ChatException('member_chat.not_found', 404);
            }

            $this->participants->setMuted($conversationId, $memberId, $muted, time());
        });
    }
}
