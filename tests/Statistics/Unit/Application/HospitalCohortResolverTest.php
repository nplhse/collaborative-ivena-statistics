<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use App\Statistics\Application\Cohort\HospitalCohortResolver;
use PHPUnit\Framework\TestCase;

final class HospitalCohortResolverTest extends TestCase
{
    public function testAllMatrixKeysResolveToSingleLocationAndTier(): void
    {
        $resolver = new HospitalCohortResolver();

        self::assertCount(9, HospitalCohortKey::all());

        foreach (HospitalCohortKey::all() as $key) {
            $cohort = $resolver->resolve($key);

            self::assertSame([$key->location, $key->tier], [
                $cohort->key->location,
                $cohort->key->tier,
            ]);
            self::assertCount(1, $cohort->locationCodeValues());
            self::assertCount(1, $cohort->tierCodeValues());
        }
    }

    public function testResolvesMixedExtendedCombination(): void
    {
        $key = new HospitalCohortKey(HospitalLocation::MIXED, HospitalTier::EXTENDED);
        $cohort = new HospitalCohortResolver()->resolve($key);

        self::assertSame('mixed_extended', $cohort->key->value());
        self::assertSame([2], $cohort->locationCodeValues());
        self::assertSame([2], $cohort->tierCodeValues());
    }

    public function testLegacyAdvancedAliasMapsToExtended(): void
    {
        $key = HospitalCohortKey::tryFrom('urban_advanced');
        self::assertNotNull($key);
        self::assertSame('urban_extended', $key->value());
    }
}
