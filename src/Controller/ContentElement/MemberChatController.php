<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\CoreBundle\Twig\FragmentTemplate;
use HeimrichHannot\EncoreContracts\PageAssetsTrait;
use HeimrichHannot\SimpleMemberChatBundle\Asset\EncoreExtension;
use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatReader;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationAccess;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use HeimrichHannot\SimpleMemberChatBundle\View\ChatContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsContentElement(type: 'member_chat', category: 'member_chat')]
final class MemberChatController extends AbstractContentElementController
{
    use PageAssetsTrait;

    public function __construct(
        private readonly FrontendMemberProvider $members,
        private readonly ConversationAccess $access,
        private readonly ChatReader $reader,
        private readonly ChatContextFactory $contexts,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $template->set('view', null);
        if (!$this->isBackendScope($request)) {
            $this->addPageEntrypoint(EncoreExtension::ENTRY);
            $this->getHtmlHeadBag()?->removeMetaTag('name', 'turbo-cache-control')->addMetaTag(new HtmlAttributes([
                'name' => 'turbo-cache-control',
                'content' => 'no-cache',
            ]));
            try {
                $viewerId = $this->members->requireMemberId();
            } catch (AuthenticationRequiredException) {
                $viewerId = null;
            }

            $conversation = $this->access->fromItem($viewerId !== null);
            if ($viewerId !== null) {
                $page = $this->getPageModel() ?? throw new PageNotFoundException();
                foreach ($this->contexts->create($page, $conversation) as $key => $value) {
                    $template->set($key, $value);
                }

                $template->set('embedded', true);
                $template->set('view', $this->reader->read($page, $viewerId, $conversation, includeList: true, includeMessages: true));
            }
        }

        $response = $template->getResponse();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
