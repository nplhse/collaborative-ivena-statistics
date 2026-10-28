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
     * @param array<string, array<string, int>> $profiles hospitals per size for each profile, optionally `months`
     * @param array{
     *     months: int,
     *     trend_per_year?: float|int,
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
        #[Autowire(param: 'fixtures.months')]
        private string $months = '',
    ) {
    }

    public function resolve(): FixtureVolume
    {
        $preset = $this->profiles[$this->profile]
            ?? throw new \InvalidArgumentException(sprintf('Unknown fixture profile "%s". Known: %s.', $this->profile, implode(', ', array_keys($this->profiles))));
        $hospitals = array_diff_key($preset, ['months' => true]);
        $scale = max(0.01, min(10.0, $this->scale));

        return new FixtureVolume(
            profile: $this->profile,
            hospitals: $hospitals,
            months: max(1, $this->months($preset['months'] ?? $this->baseline['months'])),
            allocationsPerDay: array_map(floatval(...), $this->baseline['allocations_per_day']),
            scale: $scale,
            mciCasesPerHospitalAndYear: max(0.0, (float) $this->baseline['mci_cases_per_hospital_and_year'] * min(1.0, $scale)),
            seed: $this->baseline['seed'],
            rebuildProjection: $this->baseline['rebuild_projection'],
            until: $this->until(),
            trendPerYear: (float) ($this->baseline['trend_per_year'] ?? 0.0),
        );
    }

    /**
     * FIXTURES_MONTHS, or the months of the preset or the baseline when it is empty.
     */
    private function months(int $default): int
    {
        $months = trim($this->months);
        if ('' === $months) {
            return $default;
        }
        if (1 !== preg_match('/^\d{1,3}$/', $months)) {
            throw new \InvalidArgumentException(sprintf('FIXTURES_MONTHS must be a whole number like 24, got "%s".', $this->months));
        }

        return (int) $months;
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
