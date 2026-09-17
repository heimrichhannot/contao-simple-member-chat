<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\EventListener;

use Contao\CoreBundle\Event\CloseAccountEvent;
use HeimrichHannot\SimpleMemberChatBundle\Service\MemberDataEraser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class CloseAccountEventListener
{
    public function __construct(
        private MemberDataEraser $eraser,
    ) {
    }

    public function __invoke(CloseAccountEvent $event): void
    {
        if ($event->getContentModel()->reg_close === 'close_delete') {
            $this->eraser->erase((int) $event->getMember()->id);
        }
    }
}
