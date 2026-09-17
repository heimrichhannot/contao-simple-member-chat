<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\Twig;

use Twig\Attribute\AsTwigFilter;

final class MessageRuntime
{
    #[AsTwigFilter('member_chat_autolink', isSafe: ['html'])]
    public function autolink(string $text): string
    {
        // Split raw text first: an entity supplied by a member must stay literal.
        $parts = preg_split('~(https?://[^\s<>"\x00-\x1f\x7f]+)~iu', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        }

        $html = '';
        foreach ($parts as $index => $part) {
            $escaped = htmlspecialchars($part, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            $html .= $index % 2 === 1 ? '<a href="' . $escaped . '" rel="noopener nofollow" target="_blank">' . $escaped . '</a>' : $escaped;
        }

        return $html;
    }
}
