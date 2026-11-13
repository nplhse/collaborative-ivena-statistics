<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Version of the offline data preparation tool the server renders (ADR 026, #1051), configured under
 * `app.encounter.data_prep`.
 */
final readonly class DataPrepRelease
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        #[Autowire(param: 'app.encounter.data_prep.version')]
        public string $version,
        #[Autowire(param: 'app.encounter.data_prep.min_version')]
        public string $minVersion,
        #[Autowire(param: 'app.encounter.data_prep.released_at')]
        public string $releasedAt,
    ) {
    }

    public function fileName(): string
    {
        return sprintf('encounter-data-prep-%s.html', $this->version);
    }
}
