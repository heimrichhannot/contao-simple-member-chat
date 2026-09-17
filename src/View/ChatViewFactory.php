<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View;

use Contao\PageModel;
use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactResolver;
use HeimrichHannot\SimpleMemberChatBundle\Domain\ConversationListItem;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Message;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatPageUrlGenerator;
use HeimrichHannot\SimpleMemberChatBundle\View\Model\ChatView;
use HeimrichHannot\SimpleMemberChatBundle\View\Model\ConversationItemView;
use HeimrichHannot\SimpleMemberChatBundle\View\Model\MessageView;

final readonly class ChatViewFactory
{
    public function __construct(
        private ContactResolver $contacts,
        private ChatPageUrlGenerator $urls,
    ) {
    }

    /** @param list<ConversationListItem> $items
     * @param list<Message> $messages
     */
    public function create(PageModel $page, int $viewerId, array $items = [], array $messages = [], ?int $partnerId = null, int $partnerReadId = 0): ChatView
    {
        $ids = $partnerId === null ? [] : [$partnerId];
        foreach ($items as $item) {
            $ids[] = $item->partnerId;
        }

        foreach ($messages as $message) {
            $ids[] = $message->authorId;
        }

        $contacts = $this->contacts->resolveMany(array_values(array_unique($ids)));
        $list = [];
        $changedAt = 0;
        foreach ($items as $item) {
            $conversation = $item->conversation;
            $changedAt = max($changedAt, $item->changedAt);
            $list[] = new ConversationItemView($conversation->uuid, $contacts[$item->partnerId], $this->urls->generate($page, $conversation->uuid), mb_substr($item->lastMessage->body ?? '', 0, 100), $conversation->lastMessageAt, $item->changedAt, $item->unreadCount, $item->muted);
        }

        $history = [];
        $lastId = 0;
        foreach ($messages as $message) {
            $lastId = max($lastId, $message->id);
            $history[] = new MessageView($message->id, $contacts[$message->authorId], $message->body, $message->createdAt, $message->authorId === $viewerId, $partnerId !== null && $partnerId > 0 && $message->id <= $partnerReadId);
        }

        return new ChatView($list, $history, $partnerId === null ? null : $contacts[$partnerId], $lastId, $changedAt);
    }
}
