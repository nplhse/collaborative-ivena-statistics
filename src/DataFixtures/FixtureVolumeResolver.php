<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FixtureVolumeResolver
{
    /**
     * @param array{
     *     hospitals_active: int,
     *     months: int,
     *     allocations_per_day: array<string, float|int>,
     *     mci_cases: int,
     *     seed: int,
     *     rebuild_projection: bool
     * } $baseline
     */
    public function __construct(
        #[Autowire(param: 'fixtures.scale')]
        private float $scale,
        #[Autowire(param: 'fixtures.baseline')]
        private array $baseline,
    ) {
    }

    public function resolve(): FixtureVolume
    {
        $scale = max(0.01, min(10.0, $this->scale));

        return new FixtureVolume(
            hospitalsActive: max(1, $this->baseline['hospitals_active']),
            months: max(1, $this->baseline['months']),
            allocationsPerDay: array_map(floatval(...), $this->baseline['allocations_per_day']),
            scale: $scale,
            mciCases: max(0, (int) round((float) $this->baseline['mci_cases'] * min(1.0, $scale))),
            seed: $this->baseline['seed'],
            rebuildProjection: $this->baseline['rebuild_projection'],
        );
    }
}
