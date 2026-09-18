<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa;

use Symfony\Component\Messenger\Attribute\AsMessage;

// Contao's LowPriorityMessageInterface is deprecated since 5.6 and stops working
// in Contao 6; the core's own messages route through this attribute instead.
#[AsMessage('contao_prio_low')]
final readonly class SendChatPushMessage
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
