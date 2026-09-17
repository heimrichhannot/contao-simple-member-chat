<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\EventListener\Hook;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use HeimrichHannot\SimpleMemberChatBundle\Service\MemberDataEraser;

#[AsHook('closeAccount')]
final readonly class CloseAccountListener
{
    public function __construct(
        private MemberDataEraser $eraser,
    ) {
    }

    public function __invoke(int $memberId, string $mode): void
    {
        if ($mode === 'close_delete') {
            $this->eraser->erase($memberId);
        }
    }
}
