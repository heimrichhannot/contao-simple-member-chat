<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa;

use Contao\CoreBundle\Messenger\Message\LowPriorityMessageInterface;

final readonly class SendChatPushMessage implements LowPriorityMessageInterface
{
    /**
     * @param list<int> $recipientIds
     */
    public function __construct(
        public int $messageId,
        public array $recipientIds,
    ) {
    }
}
