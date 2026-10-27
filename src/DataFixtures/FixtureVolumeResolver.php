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
     *     mci_cases_per_hospital_and_year: float|int,
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
        #[Autowire(param: 'fixtures.until')]
        private string $until = '',
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
            mciCasesPerHospitalAndYear: max(0.0, (float) $this->baseline['mci_cases_per_hospital_and_year'] * min(1.0, $scale)),
            seed: $this->baseline['seed'],
            rebuildProjection: $this->baseline['rebuild_projection'],
            until: $this->until(),
        );
    }

    /**
     * FIXTURES_UNTIL as Y-m-d, or null for "until yesterday".
     */
    private function until(): ?\DateTimeImmutable
    {
        if ('' === trim($this->until)) {
            return null;
        }

        $until = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($this->until));
        if (!$until instanceof \DateTimeImmutable || $until->format('Y-m-d') !== trim($this->until)) {
            throw new \InvalidArgumentException(sprintf('FIXTURES_UNTIL must be a date like 2026-09-30, got "%s".', $this->until));
        }

        return $until;
    }
}
