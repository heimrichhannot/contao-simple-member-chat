<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Util\LocaleUtil;
use Contao\PageModel;
use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Domain\Conversation;
use HeimrichHannot\SimpleMemberChatBundle\Service\ChatPageUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;

final readonly class ChatContextFactory
{
    public function __construct(
        private ChatPageUrlGenerator $pages,
        private UrlGeneratorInterface $routes,
        private ContaoCsrfTokenManager $tokens,
        private ChatOptions $options,
        private LocaleSwitcher $locales,
        private RequestStack $requests,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(PageModel $page, ?Conversation $conversation = null): array
    {
        $locale = LocaleUtil::formatAsLocale((string) ($page->rootLanguage !== null && $page->rootLanguage !== '' ? $page->rootLanguage : $page->language));
        $this->requests->getCurrentRequest()?->setLocale($locale);
        $this->locales->setLocale($locale);
        $parameters = [
            'page' => (int) $page->id,
        ];
        $context = [
            'language' => LocaleUtil::formatAsLanguageTag($locale),
            'history' => false,
            'page_id' => (int) $page->id,
            'datim_format' => (string) $page->datimFormat,
            'options' => $this->options,
            'request_token' => $this->tokens->getDefaultTokenValue(),
            'back_url' => $this->pages->generate($page),
            'list_url' => $this->routes->generate('contao_member_chat_conversations', $parameters),
            'search_url' => $this->routes->generate('contao_member_chat_contacts'),
            'open_url' => $this->routes->generate('contao_member_chat_conversation_create'),
            'conversation_uuid' => $conversation?->uuid,
            // Only the embedding page sets src/loading on frames: Turbo rejects a
            // frame response whose src references the request URL and empties the frame.
            'embedded' => false,
            'query' => '',
            'contacts' => [],
            'error' => null,
            'text' => '',
        ];
        if ($conversation instanceof Conversation) {
            $parameters['uuid'] = $conversation->uuid;
            $context['messages_url'] = $this->routes->generate('contao_member_chat_messages', $parameters);
            $context['compose_url'] = $this->routes->generate('contao_member_chat_compose', $parameters);
            $context['mute_url'] = $this->routes->generate('contao_member_chat_mute', $parameters);
            $context['send_url'] = $this->routes->generate('contao_member_chat_message_create', $parameters);
        }

        return $context;
    }
}
