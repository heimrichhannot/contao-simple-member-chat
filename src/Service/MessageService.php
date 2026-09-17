<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\Event\MessageSentEvent;
use HeimrichHannot\SimpleMemberChatBundle\Exception\ChatException;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ConversationVoter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class MessageService
{
    public function __construct(
        private ConversationGatewayInterface $conversations,
        private ParticipantGatewayInterface $participants,
        private MessageGatewayInterface $messages,
        private MessageTextSanitizer $sanitizer,
        private FrontendMemberProvider $members,
        private AuthorizationCheckerInterface $authorization,
        private RateLimiterFactoryInterface $rateLimiter,
        private ChatTransaction $transaction,
        private ChatEventDispatcher $events,
    ) {
    }

    public function send(int $conversationId, int $authorId, string $body): Message
    {
        if ($this->members->requireMemberId() !== $authorId) {
            throw new ChatException('member_chat.not_found', 404);
        }

        $body = $this->sanitizer->sanitize($body);
        if (!$this->rateLimiter->create((string) $authorId)->consume()->isAccepted()) {
            throw new ChatException('member_chat.rate_limited', 429);
        }

        $event = $this->transaction->run(function () use ($conversationId, $authorId, $body): MessageSentEvent {
            // Serializes sends, read tracking, muting and member erasure.
            $conversation = $this->conversations->find($conversationId, true);
            if (!$conversation instanceof Conversation || !$this->authorization->isGranted(ConversationVoter::VIEW, $conversation)) {
                throw new ChatException('member_chat.not_found', 404);
            }

            $memberIds = $this->participants->memberIds($conversationId);
            if (!\in_array($authorId, $memberIds, true)) {
                throw new ChatException('member_chat.not_found', 404);
            }

            if (\count($memberIds) !== 2) {
                throw new ChatException('member_chat.read_only', 403);
            }

            $message = $this->messages->insert($conversationId, $authorId, $body, time());
            $conversation = $this->conversations->updateLastMessage($conversation, $message);
            $recipients = array_values(array_filter($memberIds, static fn (int $id): bool => $id !== $authorId));

            return new MessageSentEvent($message, $conversation, $authorId, $recipients);
        });
        $this->events->dispatch($event);

        return $event->message;
    }
}
