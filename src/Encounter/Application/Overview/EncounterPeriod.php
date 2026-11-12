<?php

declare(strict_types=1);

namespace App\Encounter\Application\Overview;

/**
 * Period filter of the encounter overview (#1055): whole calendar days, both bounds inclusive and
 * optional. A visit belongs to the period by its time anchor (`encounter.anchor_at`: first assessment
 * contact, else ED admission; local wall clock as exchanged), the same day the import coverage uses.
 * Internally the period is the half-open window `[from 00:00, to + 1 day 00:00)`.
 *
 * Presets (EncounterPeriodPreset) only fill the two bounds: a year is 1 January – 31 December, a month
 * its first to last day, "last 12 months" starts on the first day of the month eleven months before
 * today's and has no upper bound, as in Statistics.
 */
final readonly class EncounterPeriod
{
    private function __construct(
        public ?\DateTimeImmutable $from,
        public ?\DateTimeImmutable $to,
        public EncounterPeriodPreset $preset = EncounterPeriodPreset::Custom,
    ) {
    }

    public static function allTime(): self
    {
        return new self(null, null, EncounterPeriodPreset::AllTime);
    }

    /**
     * Resolves the drawer input. Unknown presets and incomplete values fall back to all data.
     *
     * @param string|null $year  `YYYY` for the year preset
     * @param string|null $month `YYYY-MM` for the month preset
     */
    public static function fromPreset(?string $preset, ?string $year, ?string $month, ?string $from, ?string $to, \DateTimeImmutable $today): self
    {
        $choice = EncounterPeriodPreset::tryFrom((string) $preset);
        if (null === $choice && (self::parseDay($from) instanceof \DateTimeImmutable || self::parseDay($to) instanceof \DateTimeImmutable)) {
            $choice = EncounterPeriodPreset::Custom;
        }

        switch ($choice) {
            case EncounterPeriodPreset::Last12Months:
                $start = new \DateTimeImmutable($today->format('Y-m-01'))->modify('-11 months');

                return new self($start, null, EncounterPeriodPreset::Last12Months);
            case EncounterPeriodPreset::Year:
                if (null !== $year && 1 === preg_match('/^\d{4}$/', $year)) {
                    return new self(new \DateTimeImmutable($year.'-01-01'), new \DateTimeImmutable($year.'-12-31'), EncounterPeriodPreset::Year);
                }
                break;
            case EncounterPeriodPreset::Month:
                $first = null === $month ? null : self::parseDay($month.'-01');
                if ($first instanceof \DateTimeImmutable) {
                    return new self($first, $first->modify('last day of this month'), EncounterPeriodPreset::Month);
                }
                break;
            case EncounterPeriodPreset::Custom:
                $custom = self::fromQuery($from, $to);
                if (!$custom->isAllTime()) {
                    return $custom;
                }
                break;
            default:
                break;
        }

        return self::allTime();
    }

    /**
     * Reads `YYYY-MM-DD` values; anything else leaves that bound open. Swapped bounds are put in order.
     */
    public static function fromQuery(?string $from, ?string $to): self
    {
        $fromDay = self::parseDay($from);
        $toDay = self::parseDay($to);
        if ($fromDay instanceof \DateTimeImmutable && $toDay instanceof \DateTimeImmutable && $fromDay > $toDay) {
            [$fromDay, $toDay] = [$toDay, $fromDay];
        }

        return new self($fromDay, $toDay, !$fromDay instanceof \DateTimeImmutable && !$toDay instanceof \DateTimeImmutable ? EncounterPeriodPreset::AllTime : EncounterPeriodPreset::Custom);
    }

    /**
     * Query parameters that select this period again (tabs, drawer, links).
     *
     * @return array<string, string>
     */
    public function queryParameters(): array
    {
        return match ($this->preset) {
            EncounterPeriodPreset::AllTime => [],
            EncounterPeriodPreset::Last12Months => ['period' => $this->preset->value],
            EncounterPeriodPreset::Year => ['period' => $this->preset->value, 'year' => (string) $this->from?->format('Y')],
            EncounterPeriodPreset::Month => ['period' => $this->preset->value, 'month' => (string) $this->from?->format('Y-m')],
            EncounterPeriodPreset::Custom => array_filter([
                'period' => $this->preset->value,
                'from' => (string) $this->from?->format('Y-m-d'),
                'to' => (string) $this->to?->format('Y-m-d'),
            ], static fn (string $value): bool => '' !== $value),
        };
    }

    /** First instant after the period, or null when it has no upper bound. */
    public function endExclusive(): ?\DateTimeImmutable
    {
        return $this->to?->modify('+1 day');
    }

    public function isAllTime(): bool
    {
        return !$this->from instanceof \DateTimeImmutable && !$this->to instanceof \DateTimeImmutable;
    }

    private static function parseDay(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== $value) {
            return null;
        }

        return $day;
    }
}
