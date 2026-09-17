<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Controller;

use Contao\CoreBundle\Exception\PageNotFoundException;
use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Exception\ChatException;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatPageUrlGenerator;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatReader;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationAccess;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationService;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use HeimrichHannot\SimpleMemberChatBundle\Service\MessageService;
use HeimrichHannot\SimpleMemberChatBundle\View\ChatContextFactory;
use HeimrichHannot\SimpleMemberChatBundle\View\TurboResponseFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class ChatActionController
{
    public function __construct(
        private FrontendMemberProvider $members,
        private ConversationAccess $access,
        private MessageService $messages,
        private ConversationService $conversations,
        private ChatReader $reader,
        private ChatPageUrlGenerator $pages,
        private ChatContextFactory $contexts,
        private TurboResponseFactory $responses,
        private Environment $twig,
    ) {
    }

    #[Route('/_member_chat/conversations/{uuid}/messages', name: 'contao_member_chat_message_create', requirements: [
        'uuid' => ConversationAccess::UUID_PATTERN,
    ], defaults: [
        '_token_check' => true,
    ], methods: ['POST'])]
    public function message(string $uuid, Request $request): Response
    {
        try {
            $viewerId = $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return $this->responses->html('', 401);
        }

        $conversation = $this->access->requireUuid($uuid);
        $page = $this->pages->page($request->request->getInt('page'));
        $context = $this->contexts->create($page, $conversation);
        $text = $request->request->getString('text');
        try {
            $message = $this->messages->send($conversation->id, $viewerId, $text);
        } catch (ChatException $chatException) {
            if ($chatException->statusCode === 404) {
                throw new PageNotFoundException($chatException->getMessage(), $chatException->getCode(), $chatException);
            }

            $context['error'] = $chatException->translationKey;
            $context['text'] = $text;

            return $this->responses->html($this->twig->render('@Contao/member_chat/compose_form.html.twig', $context), $chatException->statusCode);
        }

        $context['view'] = $this->reader->read($page, $viewerId, $conversation, sent: $message);

        return $this->responses->stream($this->twig->render('@Contao/member_chat/sent.stream.html.twig', $context));
    }

    #[Route('/_member_chat/conversations', name: 'contao_member_chat_conversation_create', defaults: [
        '_token_check' => true,
    ], methods: ['POST'])]
    public function conversation(Request $request): Response
    {
        try {
            $viewerId = $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return $this->responses->html('', 401);
        }

        $page = $this->pages->page($request->request->getInt('page'));
        try {
            $conversation = $this->conversations->openWith($viewerId, $request->request->getInt('member'));
        } catch (ChatException $chatException) {
            $context = $this->contexts->create($page);
            $context['error'] = $chatException->translationKey;
            $context['query'] = $request->request->getString('q');

            return $this->responses->html($this->twig->render('@Contao/member_chat/contact_results.html.twig', $context), $chatException->statusCode);
        }

        return new RedirectResponse($this->pages->generate($page, $conversation->uuid), Response::HTTP_SEE_OTHER, [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Accept',
        ]);
    }
}
