<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Exception;

class ChatException extends \RuntimeException
{
    public function __construct(
        public readonly string $translationKey,
        public readonly int $statusCode,
    ) {
        parent::__construct($translationKey);
    }
}
