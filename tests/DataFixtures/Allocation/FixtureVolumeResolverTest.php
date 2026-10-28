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
        'medium' => ['Large' => 2, 'Medium' => 2, 'Small' => 2, 'closure_share' => 0.01],
        'broken' => ['Large' => 1, 'closure_share' => 1.5],
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

    public function testAPresetCanSetTheClosureShare(): void
    {
        $medium = $this->resolver('medium')->resolve();

        self::assertSame(0.01, $medium->closureShare);
        self::assertSame(['Large' => 2, 'Medium' => 2, 'Small' => 2], $medium->hospitals, 'closure_share is not a hospital size');
        self::assertSame(0.01, $medium->withSeed(741)->closureShare, 'kept with another seed');
        self::assertSame(0.01, $medium->withPatterns('tuned.json')->closureShare, 'kept with another pattern source');
        self::assertNull($this->resolver('large')->resolve()->closureShare, 'without it the structural shares apply');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('closure_share of fixture profile "broken" must be a number from 0 to below 1, got 1.5.');
        $this->resolver('broken')->resolve();
    }

    private function resolver(string $profile, string $months = ''): FixtureVolumeResolver
    {
        return new FixtureVolumeResolver($profile, self::PROFILES, 1.0, self::BASELINE, '', $months);
    }
}
