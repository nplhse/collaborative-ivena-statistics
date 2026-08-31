<?php

declare(strict_types=1);

namespace App\Shared\Application\DateTime;

/** @psalm-suppress PossiblyUnusedProperty Consumed by the RelativeTimestamp Twig component. */
final readonly class FormattedRelativeTimestamp
{
    public function __construct(
        public string $relativeLabel,
        public string $absoluteLabel,
        public string $iso8601,
    ) {
    }
}
