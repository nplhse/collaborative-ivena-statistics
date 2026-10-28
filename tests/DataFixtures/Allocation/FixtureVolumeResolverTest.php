<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Allocation;

use App\DataFixtures\FixtureVolumeResolver;
use PHPUnit\Framework\TestCase;

final class FixtureVolumeResolverTest extends TestCase
{
    private const array PROFILES = [
        'realistic' => ['Large' => 3, 'Medium' => 6, 'Small' => 6],
        'large' => ['Large' => 3, 'Medium' => 8, 'Small' => 9, 'months' => 24],
    ];

    private const array BASELINE = [
        'months' => 12,
        'trend_per_year' => 0.02,
        'allocations_per_day' => ['Small' => 10, 'Medium' => 15, 'Large' => 40],
        'mci_cases_per_hospital_and_year' => 2,
        'seed' => 740,
        'rebuild_projection' => true,
    ];

    public function testAPresetCanSetItsOwnMonths(): void
    {
        $large = $this->resolver('large')->resolve();

        self::assertSame(24, $large->months);
        self::assertSame(['Large' => 3, 'Medium' => 8, 'Small' => 9], $large->hospitals, 'months is not a hospital size');
        self::assertSame(12, $this->resolver('realistic')->resolve()->months, 'without months the baseline applies');
        self::assertSame(0.02, $large->trendPerYear);
    }

    public function testFixturesMonthsOverridesThePreset(): void
    {
        self::assertSame(3, $this->resolver('large', '3')->resolve()->months);

        $this->expectException(\InvalidArgumentException::class);
        $this->resolver('large', 'two years')->resolve();
    }

    private function resolver(string $profile, string $months = ''): FixtureVolumeResolver
    {
        return new FixtureVolumeResolver($profile, self::PROFILES, 1.0, self::BASELINE, '', $months);
    }
}
