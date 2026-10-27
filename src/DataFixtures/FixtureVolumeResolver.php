<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns the fixture configuration and the chosen profile (FIXTURES_PROFILE) into a volume.
 */
final readonly class FixtureVolumeResolver
{
    /**
     * @param array<string, array<string, int>> $profiles hospitals per size for each profile
     * @param array{
     *     months: int,
     *     allocations_per_day: array<string, float|int>,
     *     mci_cases: int,
     *     seed: int,
     *     rebuild_projection: bool
     * } $baseline
     */
    public function __construct(
        #[Autowire(param: 'fixtures.profile')]
        private string $profile,
        #[Autowire(param: 'fixtures.profiles')]
        private array $profiles,
        #[Autowire(param: 'fixtures.scale')]
        private float $scale,
        #[Autowire(param: 'fixtures.baseline')]
        private array $baseline,
    ) {
    }

    public function resolve(): FixtureVolume
    {
        $hospitals = $this->profiles[$this->profile]
            ?? throw new \InvalidArgumentException(sprintf('Unknown fixture profile "%s". Known: %s.', $this->profile, implode(', ', array_keys($this->profiles))));
        $scale = max(0.01, min(10.0, $this->scale));

        return new FixtureVolume(
            profile: $this->profile,
            hospitals: $hospitals,
            months: max(1, $this->baseline['months']),
            allocationsPerDay: array_map(floatval(...), $this->baseline['allocations_per_day']),
            scale: $scale,
            mciCases: max(0, (int) round((float) $this->baseline['mci_cases'] * min(1.0, $scale))),
            seed: $this->baseline['seed'],
            rebuildProjection: $this->baseline['rebuild_projection'],
        );
    }
}
