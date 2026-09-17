<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Event\ConversationCreatedEvent;
use HeimrichHannot\SimpleMemberChatBundle\Exception\ChatException;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ConversationService
{
    public function __construct(
        private ConversationGatewayInterface $conversations,
        private ParticipantGatewayInterface $participants,
        private ContactPermissionInterface $contactPermission,
        private FrontendMemberProvider $members,
        private RateLimiterFactoryInterface $rateLimiter,
        private ChatTransaction $transaction,
        private ChatEventDispatcher $events,
    ) {
    }

    /**
     * Phase 2 resolves Viewer inside the injected contact permission adapter.
     */
    public function openWith(int $initiatorId, int $memberId): Conversation
    {
        if ($this->members->requireMemberId() !== $initiatorId || $memberId <= 0 || $memberId === $initiatorId) {
            throw new ChatException('member_chat.contact_denied', 403);
        }

        $low = min($initiatorId, $memberId);
        $high = max($initiatorId, $memberId);
        $existing = $this->conversations->findByPair($low, $high);
        if ($existing instanceof Conversation) {
            return $existing;
        }

        if (!$this->rateLimiter->create((string) $initiatorId)->consume()->isAccepted()) {
            throw new ChatException('member_chat.rate_limited', 429);
        }

        if (!$this->contactPermission->canContact($initiatorId, $memberId)) {
            throw new ChatException('member_chat.contact_denied', 403);
        }

        try {
            $conversation = $this->transaction->run(function () use ($low, $high): Conversation {
                $now = time();
                $conversation = $this->conversations->insert(Uuid::v7()->toRfc4122(), $low, $high, $now);
                $this->participants->add($conversation->id, $low, $now);
                $this->participants->add($conversation->id, $high, $now);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            // The failed transaction is rolled back before looking up the winner.
            $existing = $this->conversations->findByPair($low, $high);
            if (!$existing instanceof Conversation) {
                throw $uniqueConstraintViolationException;
            }

            return $existing;
        }

        $this->events->dispatch(new ConversationCreatedEvent($conversation, $initiatorId));

        return $conversation;
    }
}
