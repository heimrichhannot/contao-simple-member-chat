<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Event\MessagesReadEvent;
use HeimrichHannot\SimpleMemberChatBundle\Exception\ChatException;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;

final readonly class ReadTracker
{
    public function __construct(
        private ConversationGatewayInterface $conversations,
        private ParticipantGatewayInterface $participants,
        private MessageGatewayInterface $messages,
        private FrontendMemberProvider $members,
        private ChatTransaction $transaction,
        private ChatEventDispatcher $events,
    ) {
    }

    public function markRead(int $conversationId, int $memberId, int $upToMessageId, ?int $pageId = null): void
    {
        if ($this->members->requireMemberId() !== $memberId) {
            throw new ChatException('member_chat.not_found', 404);
        }

        if ($upToMessageId < 0 || ($pageId !== null && $pageId < 1)) {
            throw new \InvalidArgumentException('Invalid read position or page ID.');
        }

        $changed = $this->transaction->run(function () use ($conversationId, $memberId, $upToMessageId, $pageId): bool {
            if (!$this->conversations->find($conversationId, true) instanceof Conversation || $this->participants->state($conversationId, $memberId) === null) {
                throw new ChatException('member_chat.not_found', 404);
            }

            if ($upToMessageId !== 0 && $this->messages->find($upToMessageId)?->conversationId !== $conversationId) {
                throw new ChatException('member_chat.invalid_read_position', 422);
            }

            return $this->participants->markRead($conversationId, $memberId, $upToMessageId, time(), $pageId);
        });
        if ($changed) {
            $this->events->dispatch(new MessagesReadEvent($conversationId, $memberId, $upToMessageId));
        }
    }
}
