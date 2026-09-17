<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ChatPageUrlGenerator
{
    public function __construct(
        private ContaoFramework $framework,
        #[Autowire(service: 'contao.routing.content_url_generator')] private ContentUrlGenerator $urls,
    ) {
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
