<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View\Model;

use HeimrichHannot\SimpleMemberChatBundle\Domain\Contact;

final readonly class MessageView
{
    public function __construct(
        public int $id,
        public Contact $author,
        public string $body,
        public int $createdAt,
        public bool $own,
        public bool $readByPartner,
        public ?DaySeparatorView $daySeparator = null,
    ) {
    }
}
