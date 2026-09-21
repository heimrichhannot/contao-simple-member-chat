<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Controller;

use HeimrichHannot\SimpleMemberChatBundle\Contact\ContactService;
use HeimrichHannot\SimpleMemberChatBundle\Exception\AuthenticationRequiredException;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatReader;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationAccess;
use HeimrichHannot\SimpleMemberChatBundle\Service\ConversationUrlGenerator;
use HeimrichHannot\SimpleMemberChatBundle\Service\FrontendMemberProvider;
use HeimrichHannot\SimpleMemberChatBundle\View\ChatContextFactory;
use HeimrichHannot\SimpleMemberChatBundle\View\ConversationPollFingerprint;
use HeimrichHannot\SimpleMemberChatBundle\View\TurboResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class ChatFrameController
{
    public function __construct(
        private FrontendMemberProvider $members,
        private ConversationAccess $access,
        private ChatReader $reader,
        private ConversationUrlGenerator $pages,
        private ChatContextFactory $contexts,
        private TurboResponseFactory $responses,
        private Environment $twig,
        private ContactService $contacts,
    ) {
    }

    #[Route('/_member_chat/conversations', name: 'contao_member_chat_conversations', methods: ['GET'])]
    public function conversations(Request $request): Response
    {
        try {
            $viewerId = $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return $this->responses->html('', 401);
        }

        $page = $this->pages->page($request->query->getInt('page'));
        $since = $request->query->has('since') ? max(0, $request->query->getInt('since')) : null;
        $before = $request->query->getString('before');
        if ($before !== '' && ($since !== null || preg_match('/^(0|[1-9][0-9]*),([1-9][0-9]*)$/D', $before) !== 1)) {
            throw new BadRequestHttpException('Invalid conversation window.');
        }

        $cursor = $before === '' ? null : array_map(intval(...), explode(',', $before));
        $context = $this->contexts->create($page);
        // Only a marker for the list: never looked up, so a syntax check is enough.
        $current = $request->query->getString('current');
        $context['conversation_uuid'] = preg_match('/^' . ConversationAccess::UUID_PATTERN . '$/D', $current) === 1 ? $current : null;
        $context['history'] = $cursor !== null;
        $context['view'] = $this->reader->read($page, $viewerId, includeList: true, since: $since, beforeTimestamp: $cursor[0] ?? null, beforeId: $cursor[1] ?? null);
        if ($since !== null || $cursor !== null) {
            $unchanged = $since !== null && ($context['view']->conversations === [] || $request->query->getString('fingerprint') === ConversationPollFingerprint::create($context['view']->conversations, $since));
            $response = $unchanged ? $this->responses->html('', 204) : $this->responses->stream($this->twig->render('@Contao/member_chat/conversations.stream.html.twig', $context));
            if ($cursor !== null) {
                $response->headers->set('X-Chat-Before', $context['view']->beforeConversation ?? '');
            } else {
                $nextSince = max($since ?? 0, $context['view']->changedAt);
                $response->headers->set('X-Chat-Since', (string) $nextSince);
                $response->headers->set('X-Chat-Fingerprint', ConversationPollFingerprint::create($context['view']->conversations, $nextSince));
            }

            return $response;
        }

        return $this->responses->html($this->twig->render('@Contao/member_chat/conversation_list.html.twig', $context));
    }

    #[Route('/_member_chat/conversations/{uuid}/messages', name: 'contao_member_chat_messages', requirements: [
        'uuid' => ConversationAccess::UUID_PATTERN,
    ], methods: ['GET'])]
    public function messages(string $uuid, Request $request): Response
    {
        try {
            $viewerId = $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return $this->responses->html('', 401);
        }

        $conversation = $this->access->requireUuid($uuid);
        $page = $this->pages->page($request->query->getInt('page'));
        $after = $request->query->has('after') ? max(0, $request->query->getInt('after')) : null;
        $before = $request->query->has('before') ? $request->query->getInt('before') : null;
        if ($before !== null && ($before < 1 || $after !== null)) {
            throw new BadRequestHttpException('Invalid message window.');
        }

        $context = $this->contexts->create($page, $conversation);
        $context['history'] = $before !== null;
        $view = $this->reader->read($page, $viewerId, $conversation, includeMessages: true, after: $after, before: $before);
        if ($after !== null && $view->messages === []) {
            return $this->responses->html('', 204);
        }

        $context['view'] = $view;
        if ($after !== null || $before !== null) {
            $response = $this->responses->stream($this->twig->render('@Contao/member_chat/messages.stream.html.twig', $context));
            if ($before !== null) {
                $response->headers->set('X-Chat-Before', (string) $view->beforeMessageId);
            } else {
                $response->headers->set('X-Chat-After', (string) $view->lastMessageId);
            }

            $response->headers->set('X-Chat-Count', (string) \count($view->messages));

            return $response;
        }

        return $this->responses->html($this->twig->render('@Contao/member_chat/message_list.html.twig', $context));
    }

    #[Route('/_member_chat/conversations/{uuid}/compose', name: 'contao_member_chat_compose', requirements: [
        'uuid' => ConversationAccess::UUID_PATTERN,
    ], methods: ['GET'])]
    public function compose(string $uuid, Request $request): Response
    {
        try {
            $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return $this->responses->html('', 401);
        }

        $conversation = $this->access->requireUuid($uuid);
        $context = $this->contexts->create($this->pages->page($request->query->getInt('page')), $conversation);

        return $this->responses->html($this->twig->render('@Contao/member_chat/compose_form.html.twig', $context));
    }

    #[Route('/_member_chat/contacts', name: 'contao_member_chat_contacts', methods: ['GET'])]
    public function contacts(Request $request): Response
    {
        try {
            $this->members->requireMemberId();
        } catch (AuthenticationRequiredException) {
            return $this->responses->html('', 401);
        }

        $context = $this->contexts->create($this->pages->page($request->query->getInt('page')));
        $context['query'] = trim($request->query->getString('q'));
        $context['contacts'] = $this->contacts->search($request->query->getString('q'));

        return $this->responses->html($this->twig->render('@Contao/member_chat/contact_results.html.twig', $context));
    }
}
