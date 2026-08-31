<?php

declare(strict_types=1);

namespace App\Shared\Application\DateTime;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class RelativeTimestampFormatter
{
    private const string TIMEZONE = 'Europe/Berlin';

    private const int JUST_NOW_SECONDS = 45;

    private const int SECONDS_PER_MINUTE = 60;

    private const int SECONDS_PER_HOUR = 3600;

    private const int MINUTES_PER_HOUR = 60;

    private const int DAYS_PER_WEEK = 7;

    private const int WEEK_BUCKET_LIMIT = 5;

    private const int MONTHS_PER_YEAR = 12;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    public function format(\DateTimeInterface $datetime): FormattedRelativeTimestamp
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $now = $this->clock->now()->setTimezone($timezone);
        $then = \DateTimeImmutable::createFromInterface($datetime)->setTimezone($timezone);

        return new FormattedRelativeTimestamp(
            relativeLabel: $this->relativeLabel($now, $then),
            absoluteLabel: $this->absoluteLabel($then),
            iso8601: $then->format(\DateTimeInterface::ATOM),
        );
    }

    private function relativeLabel(\DateTimeImmutable $now, \DateTimeImmutable $then): string
    {
        $seconds = $now->getTimestamp() - $then->getTimestamp();
        if ($seconds < self::JUST_NOW_SECONDS) {
            return $this->trans('time.relative.just_now');
        }

        $minutes = intdiv($seconds, self::SECONDS_PER_MINUTE);
        if ($minutes < self::MINUTES_PER_HOUR) {
            return $this->trans('time.relative.minute', ['count' => max(1, $minutes)]);
        }

        $calendarDays = $this->calendarDays($now, $then);
        if (0 === $calendarDays) {
            return $this->trans('time.relative.hour', ['count' => max(1, intdiv($seconds, self::SECONDS_PER_HOUR))]);
        }

        if (1 === $calendarDays) {
            return $this->trans('time.relative.yesterday');
        }

        if ($calendarDays < self::DAYS_PER_WEEK) {
            return $this->trans('time.relative.day', ['count' => $calendarDays]);
        }

        $weeks = intdiv($calendarDays, self::DAYS_PER_WEEK);
        if ($weeks < self::WEEK_BUCKET_LIMIT) {
            return $this->trans('time.relative.week', ['count' => max(1, $weeks)]);
        }

        $months = $this->calendarMonths($now, $then);
        if ($months < self::MONTHS_PER_YEAR) {
            return $this->trans('time.relative.month', ['count' => max(1, $months)]);
        }

        return $this->trans('time.relative.year', ['count' => max(1, intdiv($months, self::MONTHS_PER_YEAR))]);
    }

    private function absoluteLabel(\DateTimeImmutable $then): string
    {
        $locale = $this->translator->getLocale();
        $pattern = str_starts_with($locale, 'de') ? 'd. MMMM y, HH:mm' : 'd MMMM y, HH:mm';
        $formatted = \IntlDateFormatter::formatObject($then, $pattern, $locale);

        if (!\is_string($formatted) || '' === $formatted) {
            return $then->format('d.m.Y H:i');
        }

        return $formatted;
    }

    private function calendarDays(\DateTimeImmutable $now, \DateTimeImmutable $then): int
    {
        return (int) $then->setTime(0, 0)->diff($now->setTime(0, 0))->format('%a');
    }

    private function calendarMonths(\DateTimeImmutable $now, \DateTimeImmutable $then): int
    {
        $months = (((int) $now->format('Y')) - ((int) $then->format('Y'))) * self::MONTHS_PER_YEAR
            + ((int) $now->format('n') - (int) $then->format('n'));

        if ((int) $now->format('j') < (int) $then->format('j')) {
            --$months;
        }

        return max(0, $months);
    }

    /**
     * @param array<string, int> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'shared');
    }
}
