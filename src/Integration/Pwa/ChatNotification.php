<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Integration\Pwa;

use HeimrichHannot\PwaBundle\Notification\DefaultNotification;

final class ChatNotification extends DefaultNotification
{
    public function __construct(
        string $title,
        ?string $body,
        private readonly ?string $url,
    ) {
        parent::__construct();
        $this->setTitle($title);
        $this->setBody($body);
    }

    /**
     * @return array{clickJumpTo: string}|null
     */
    public function getData(): ?array
    {
        return $this->url === null ? null : [
            'clickJumpTo' => $this->url,
        ];
    }
}
