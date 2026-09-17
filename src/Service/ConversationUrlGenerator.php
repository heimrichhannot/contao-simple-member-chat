<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\Model\Collection;
use Contao\PageModel;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Gateway\ParticipantGatewayInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConversationUrlGenerator
{
    public function __construct(
        private ContaoFramework $framework,
        #[Autowire(service: 'contao.routing.content_url_generator')] private ContentUrlGenerator $urls,
        private ParticipantGatewayInterface $participants,
    ) {
    }

    /**
     * Stable integration API. Returns null when no published destination exists.
     */
    public function forConversation(Conversation $conversation, int $memberId): ?string
    {
        $page = $this->resolvePage($this->participants->state($conversation->id, $memberId)['lastPageId'] ?? 0);

        return $page instanceof PageModel ? $this->generate($page, $conversation->uuid) : null;
    }

    /**
     * Stable integration API. The most recently visited conversation supplies the page.
     */
    public function listPage(int $memberId): ?string
    {
        $page = $this->resolvePage($this->participants->lastPageId($memberId));

        return $page instanceof PageModel ? $this->generate($page) : null;
    }

    private function resolvePage(int $pageId): ?PageModel
    {
        $this->framework->initialize();
        $pages = $this->framework->getAdapter(PageModel::class);
        if ($pageId > 0) {
            $page = $pages->__call('findPublishedById', [
                $pageId, [
                    'ignoreFePreview' => true,
                ]]);
            if ($page instanceof PageModel && $page->type === 'regular') {
                $page->loadDetails();
                if ($page->isPublic && $page->rootIsPublic) {
                    return $page;
                }
            }
        }

        $roots = $pages->__call('findPublishedRootPages', [[
            'ignoreFePreview' => true,
            'order' => 'tl_page.sorting, tl_page.id',
        ]]);
        if ($roots instanceof Collection) {
            foreach ($roots as $root) {
                /** @var int|string|null $fallbackId */
                $fallbackId = $root->memberChatPage;
                if ((int) $fallbackId <= 0) {
                    continue;
                }

                $page = $pages->__call('findPublishedById', [
                    (int) $fallbackId, [
                        'ignoreFePreview' => true,
                    ]]);
                if ($page instanceof PageModel && $page->type === 'regular') {
                    $page->loadDetails();
                    if ($page->isPublic && $page->rootIsPublic) {
                        return $page;
                    }
                }
            }
        }

        return null;
    }

    public function page(int $pageId): PageModel
    {
        $this->framework->initialize();
        $page = $this->framework->getAdapter(PageModel::class)->__call('findWithDetails', [$pageId]);
        if (!$page instanceof PageModel || $page->type !== 'regular') {
            throw new PageNotFoundException();
        }

        return $page;
    }

    public function generate(PageModel $page, ?string $uuid = null): string
    {
        // An explicit empty parameter also clears a current conversation item.
        return $this->urls->generate($page, [
            'parameters' => $uuid === null ? '' : '/' . $uuid,
        ]);
    }
}
