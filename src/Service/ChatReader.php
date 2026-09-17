<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use Contao\PageModel;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\MessageGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\View\ChatViewFactory;
use HeimrichHannot\SimpleMemberChatBundle\View\Model\ChatView;

final readonly class ChatReader
{
    public function __construct(
        private ConversationGatewayInterface $conversations,
        private MessageGatewayInterface $messages,
        private ParticipantGatewayInterface $participants,
        private ReadTracker $reads,
        private ChatOptions $options,
        private ChatViewFactory $views,
    ) {
    }

    public function read(PageModel $page, int $viewerId, ?Conversation $conversation = null, bool $includeList = false, bool $includeMessages = false, ?int $since = null, ?int $after = null, ?Message $sent = null): ChatView
    {
        $messages = [];
        $partnerId = null;
        $partnerReadId = 0;
        if ($conversation instanceof Conversation) {
            $partners = array_values(array_filter($this->participants->memberIds($conversation->id), static fn (int $id): bool => $id !== $viewerId));
            $partnerId = $partners[0] ?? 0;
            $partnerReadId = $this->participants->state($conversation->id, $partnerId)['lastReadMessageId'] ?? 0;
            if ($includeMessages) {
                $messages = $this->messages->window($conversation->id, $this->options->pageSize, after: $after);
                $lastId = $messages === [] ? 0 : $messages[array_key_last($messages)]->id;
                // Never trust a client-supplied after ID as a delivered read position.
                $this->reads->markRead($conversation->id, $viewerId, $lastId, (int) $page->id);
            } elseif ($sent instanceof Message) {
                $messages = [$sent];
            }
        }

        // Query after markRead so the initial sidebar already reflects the read state.
        $items = $includeList ? $this->conversations->listForMember($viewerId, $this->options->pageSize, since: $since) : [];

        return $this->views->create($page, $viewerId, $items, $messages, $partnerId, $partnerReadId);
    }
}
