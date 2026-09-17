<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Service;

use HeimrichHannot\SimpleMemberChatBundle\Configuration\ChatOptions;
use HeimrichHannot\SimpleMemberChatBundle\Exception\ChatException;

final readonly class MessageTextSanitizer
{
    public function __construct(
        private ChatOptions $options,
    ) {
    }

    public function sanitize(string $body): string
    {
        if (!mb_check_encoding($body, 'UTF-8')) {
            throw new ChatException('member_chat.invalid_text', 422);
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        // Preserve tabs and newlines; HTML remains literal text for Twig escaping.
        $body = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u', '', $body) ?? '');
        $body = preg_replace('/^\s+|\s+$/u', '', $body) ?? '';
        if ($body === '' || mb_strlen($body, 'UTF-8') > $this->options->maxLength) {
            throw new ChatException('member_chat.invalid_length', 422);
        }

        return $body;
    }
}
