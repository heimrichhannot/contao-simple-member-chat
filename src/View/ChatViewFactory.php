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
        private DaySeparatorFactory $days,
    ) {
    }

    /** @param list<ConversationListItem> $items
     * @param list<Message> $messages
     */
    public function create(PageModel $page, int $viewerId, array $items = [], array $messages = [], ?int $partnerId = null, int $partnerReadId = 0, bool $moreMessages = false, bool $moreConversations = false, bool $muted = false): ChatView
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
        $previousDay = null;
        foreach ($messages as $message) {
            $separator = $this->days->create($message->createdAt, (string) $page->language, (string) ($page->dateFormat !== null && $page->dateFormat !== '' ? $page->dateFormat : 'Y-m-d'));
            $lastId = max($lastId, $message->id);
            $history[] = new MessageView($message->id, $contacts[$message->authorId], $message->body, $message->createdAt, $message->authorId === $viewerId, $partnerId !== null && $partnerId > 0 && $message->id <= $partnerReadId, $separator->day !== $previousDay ? $separator : null);
            $previousDay = $separator->day;
        }

        $last = $items === [] ? null : $items[array_key_last($items)]->conversation;

        return new ChatView($list, $history, $partnerId === null ? null : $contacts[$partnerId], $lastId, $changedAt,
            $moreMessages && $messages !== [] ? $messages[0]->id : null,
            $moreConversations && $last !== null ? $last->lastMessageAt . ',' . $last->id : null,
            $muted,
        );
    }
}
