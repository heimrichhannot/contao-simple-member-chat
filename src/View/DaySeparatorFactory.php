<?php

declare(strict_types=1);

namespace HeimrichHannot\SimpleMemberChatBundle\View;

use HeimrichHannot\SimpleMemberChatBundle\View\Model\DaySeparatorView;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DaySeparatorFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function create(int $timestamp, string $locale, string $dateFormat, ?int $now = null): DaySeparatorView
    {
        $date = new \DateTimeImmutable()->setTimestamp($timestamp);
        $today = new \DateTimeImmutable()->setTimestamp($now ?? time())->setTime(0, 0);
        $day = $date->format('Y-m-d');
        if ($day === $today->format('Y-m-d')) {
            $label = $this->translator->trans('member_chat.today', locale: $locale);
        } elseif ($day === $today->modify('-1 day')->format('Y-m-d')) {
            $label = $this->translator->trans('member_chat.yesterday', locale: $locale);
        } elseif ($date < $today && $date >= $today->modify('-6 days')) {
            $label = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, date_default_timezone_get(), pattern: 'EEEE')->format($timestamp);
        } else {
            $label = $date->format($dateFormat);
        }

        return new DaySeparatorView($day, $label === false ? $day : $label);
    }
}
