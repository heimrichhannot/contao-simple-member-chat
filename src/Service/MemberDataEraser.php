<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;

final readonly class MemberDataEraser
{
    public function __construct(
        private ConversationGatewayInterface $conversations,
        private ParticipantGatewayInterface $participants,
        private MessageGatewayInterface $messages,
        private ChatTransaction $transaction,
    ) {
    }

    public function erase(int $memberId): void
    {
        if ($memberId <= 0) {
            throw new \InvalidArgumentException('Only real member IDs can be erased.');
        }

        $this->transaction->run(function () use ($memberId): void {
            $ids = $this->participants->conversationIds($memberId);
            // A stable lock order avoids deadlocks when both members close accounts.
            foreach ($ids as $id) {
                $this->conversations->find($id, true);
            }

            $this->messages->anonymize($memberId);
            foreach ($ids as $id) {
                $this->participants->remove($id, $memberId);
                if ($this->participants->memberIds($id) === []) {
                    $this->conversations->delete($id);
                }
            }
        });
    }
}
