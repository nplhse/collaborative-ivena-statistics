<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * The records of an encounter import in the Explore list of its schema (#1413): button label, URL and a Tabler
 * icon (`tabler:…`).
 */
final readonly class ImportRecordsLink
{
    /**
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function __construct(
        public TranslatableInterface $label,
        public string $url,
        public string $icon,
    ) {
    }
}
