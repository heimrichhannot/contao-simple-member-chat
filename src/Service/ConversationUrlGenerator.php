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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
     *
     * $baseUrl anchors an absolute URL when the root page carries no domain. Queued
     * work has no request, and the router context then defaults to "localhost",
     * which would produce a link that is useless on the recipient's device.
     */
    public function forConversation(Conversation $conversation, int $memberId, int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH, ?string $baseUrl = null): ?string
    {
        $page = $this->resolvePage($this->participants->state($conversation->id, $memberId)['lastPageId'] ?? 0);
        if (!$page instanceof PageModel) {
            return null;
        }

        if ($referenceType !== UrlGeneratorInterface::ABSOLUTE_URL || (string) $page->domain !== '') {
            return $this->generate($page, $conversation->uuid, $referenceType);
        }

        if ($baseUrl === null || ($parts = parse_url($baseUrl)) === false || !isset($parts['host'])) {
            return null;
        }

        $context = $this->urls->getContext();
        $previous = [$context->getScheme(), $context->getHost(), $context->getHttpPort(), $context->getHttpsPort()];
        $context->setScheme($parts['scheme'] ?? 'https');
        $context->setHost($parts['host']);
        if (isset($parts['port'])) {
            $context->setHttpPort($parts['port']);
            $context->setHttpsPort($parts['port']);
        }

        try {
            return $this->generate($page, $conversation->uuid, $referenceType);
        } finally {
            $context->setScheme($previous[0]);
            $context->setHost($previous[1]);
            $context->setHttpPort($previous[2]);
            $context->setHttpsPort($previous[3]);
        }
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

    public function generate(PageModel $page, ?string $uuid = null, int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        // An explicit empty parameter also clears a current conversation item.
        return $this->urls->generate($page, [
            'parameters' => $uuid === null ? '' : '/' . $uuid,
        ], $referenceType);
    }
}
