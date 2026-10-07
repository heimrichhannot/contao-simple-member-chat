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
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Security\Voter\ChatAccessVoter;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatAccessChecker;
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
        private readonly ChatAccessChecker $chatAccess,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $template->set('view', null);
        $template->set('access_decision', null);
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

            if ($viewerId !== null) {
                $decision = $this->getAccessDecision(ChatAccessVoter::ACCESS);
                if (!$decision->isGranted) {
                    // The denied block may offer an action (e.g. a consent form) and needs the token and page URL.
                    $page = $this->getPageModel() ?? throw new PageNotFoundException();
                    foreach ($this->contexts->create($page) as $key => $value) {
                        $template->set($key, $value);
                    }

                    // Treated like an anonymous viewer: the item is consumed without a lookup.
                    $template->set('access_decision', $decision);
                    $viewerId = null;
                }
            }

            $conversation = $this->access->fromItem($viewerId !== null);
            if ($viewerId !== null) {
                $page = $this->getPageModel() ?? throw new PageNotFoundException();
                foreach ($this->contexts->create($page, $conversation) as $key => $value) {
                    $template->set($key, $value);
                }

                $template->set('embedded', true);
                $template->set('partner_access', !$conversation instanceof Conversation || $this->chatAccess->isGrantedForPartner($conversation, $viewerId));
                $template->set('view', $this->reader->read($page, $viewerId, $conversation, includeList: true, includeMessages: true));
            }
        }

        $response = $template->getResponse();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
