<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View\Model;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;

final readonly class ChatView
{
    /** @param list<ConversationItemView> $conversations
     * @param list<MessageView> $messages
     */
    public function __construct(
        public array $conversations,
        public array $messages,
        public ?Contact $partner,
        public int $lastMessageId,
        public int $changedAt,
    ) {
    }
}
