<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Input;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ConversationGatewayInterface;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ConversationVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final readonly class ConversationAccess
{
    public const string UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private ConversationGatewayInterface $conversations,
        private AuthorizationCheckerInterface $authorization,
        private ContaoFramework $framework,
    ) {
    }

    public function fromItem(bool $authenticated = true): ?Conversation
    {
        $this->framework->initialize();
        // Consume the legacy routing parameter; keeping it unused causes a later 404.
        $item = $this->framework->getAdapter(Input::class)->__call('get', ['auto_item']);
        if (!$authenticated || $item === null || $item === '') {
            return null;
        }

        if (!\is_string($item)) {
            throw new PageNotFoundException();
        }

        return $this->requireUuid($item);
    }

    public function requireUuid(string $uuid): Conversation
    {
        if (preg_match('/^' . self::UUID_PATTERN . '$/D', $uuid) !== 1) {
            throw new PageNotFoundException();
        }

        $conversation = $this->conversations->findByUuid($uuid);
        if (!$conversation instanceof Conversation || !$this->authorization->isGranted(ConversationVoter::VIEW, $conversation)) {
            throw new PageNotFoundException();
        }

        return $conversation;
    }
}
