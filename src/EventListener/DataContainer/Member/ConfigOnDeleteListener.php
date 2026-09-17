<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\EventListener\DataContainer\Member;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use HeimrichHannot\SimpleMemberChatBundle\Service\MemberDataEraser;

#[AsCallback(table: 'tl_member', target: 'config.ondelete')]
final readonly class ConfigOnDeleteListener
{
    public function __construct(
        private MemberDataEraser $eraser,
    ) {
    }

    public function __invoke(DataContainer $dc): void
    {
        $this->eraser->erase((int) $dc->id);
    }
}
