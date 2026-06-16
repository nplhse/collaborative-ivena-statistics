<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FixtureVolumeResolver
{
    /**
     * @param array{
     *     hospitals_active: int,
     *     imports: int,
     *     allocations: int,
     *     mci_cases: int,
     *     period: string,
     *     pattern: string,
     *     rebuild_projection: bool
     * } $baseline
     */
    public function __construct(
        #[Autowire('%fixtures.scale%')]
        private int $scale,
        #[Autowire('%fixtures.baseline%')]
        private array $baseline,
    ) {
    }

    public function resolve(): FixtureVolume
    {
        $scale = max(1, min(10, $this->scale));

        return new FixtureVolume(
            hospitalsActive: min(77, $this->baseline['hospitals_active'] * (int) ceil(sqrt($scale))),
            imports: $this->baseline['imports'] * $scale,
            allocations: $this->baseline['allocations'] * $scale,
            mciCases: $this->baseline['mci_cases'] * $scale,
            period: $this->resolvePeriod($scale),
            pattern: $this->baseline['pattern'],
            rebuildProjection: $this->baseline['rebuild_projection'],
        );
    }

    private function resolvePeriod(int $scale): string
    {
        $period = $this->baseline['period'];
        if ($scale >= 3) {
            return '-24 months';
        }

        return $period;
    }
}
