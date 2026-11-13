<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * One point on a Timeline: marker icon, a visible category label (so categories never rely on colour),
 * time, a one-line summary and optional details.
 *
 * - Slot `content`: the summary line. Keep links out of it when the item is expandable; the summary is the
 *   toggle.
 * - Slot `body`: details rendered with the page.
 * - `detailsUrl`: details loaded on first expansion (Stimulus `timeline-details`); without JavaScript the
 *   item shows a link to that URL instead.
 */
#[AsTwigComponent(name: 'TimelineItem', template: '@Shared/components/TimelineItem.html.twig')]
final class TimelineItem
{
    private const array TONES = ['default', 'primary', 'success', 'warning', 'danger', 'muted'];

    private const string DEFAULT_TIMEZONE = 'Europe/Berlin';

    /** UX icon name of the marker, for example `tabler:pencil`. */
    public string $icon = 'tabler:circle';

    /** Visible, translated category label next to the time. */
    public ?string $category = null;

    public ?\DateTimeInterface $time = null;

    /** Time zone for the visible time; the machine-readable datetime attribute is always UTC. */
    public string $timezone = self::DEFAULT_TIMEZONE;

    /** @psalm-suppress PossiblyUnusedProperty Consumed by TimelineItem.html.twig. */
    public string $timeFormat = 'd.m.Y H:i:s';

    public string $tone = 'default';

    public ?string $detailsUrl = null;

    /**
     * Text of the fallback link and of the loading state of lazy details.
     *
     * @psalm-suppress PossiblyUnusedProperty Consumed by TimelineItem.html.twig.
     */
    public string $detailsLabel = 'Details';

    /**
     * Shown when lazy details fail to load.
     *
     * @psalm-suppress PossiblyUnusedProperty Consumed by TimelineItem.html.twig.
     */
    public string $detailsErrorLabel = 'The details could not be loaded.';

    /** @psalm-suppress PossiblyUnusedProperty Consumed by TimelineItem.html.twig. */
    public bool $open = false;

    public function getToneClass(): string
    {
        $tone = strtolower($this->tone);

        return 'timeline-tone-'.(\in_array($tone, self::TONES, true) ? $tone : 'default');
    }

    public function getUtcTime(): ?string
    {
        return $this->time instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($this->time)->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM)
            : null;
    }

    public function getLocalTime(): ?string
    {
        return $this->time instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($this->time)->setTimezone($this->displayZone())->format($this->timeFormat)
            : null;
    }

    /**
     * The configured zone; an empty or unknown name falls back to Europe/Berlin instead of failing the page.
     */
    private function displayZone(): \DateTimeZone
    {
        if ('' === $this->timezone) {
            return new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }

        try {
            return new \DateTimeZone($this->timezone);
        } catch (\Exception) {
            return new \DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }
}
