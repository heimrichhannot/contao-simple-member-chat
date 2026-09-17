<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View;

use Symfony\Component\HttpFoundation\Response;

final readonly class TurboResponseFactory
{
    public function html(string $content, int $status = 200): Response
    {
        return new Response($content, $status, [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Accept',
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function stream(string $content): Response
    {
        return new Response($content, Response::HTTP_OK, [
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Accept',
            'Content-Type' => 'text/vnd.turbo-stream.html; charset=UTF-8',
        ]);
    }
}
