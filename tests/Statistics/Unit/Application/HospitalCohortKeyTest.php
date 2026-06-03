<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Cohort\HospitalCohortKey;
use PHPUnit\Framework\TestCase;

final class HospitalCohortKeyTest extends TestCase
{
    public function testAllBuildsLocationByTierMatrixFromDomainEnums(): void
    {
        $keys = HospitalCohortKey::all();

        self::assertCount(
            \count(HospitalLocation::cases()) * \count(HospitalTier::cases()),
            $keys,
        );
        self::assertSame('urban_basic', $keys[0]->value());
        self::assertSame('mixed_extended', new HospitalCohortKey(HospitalLocation::MIXED, HospitalTier::EXTENDED)->value());
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        self::assertNull(HospitalCohortKey::tryFrom(''));
    }

    public function testTryFromUnknownValueReturnsNull(): void
    {
        self::assertNull(HospitalCohortKey::tryFrom('unknown_cohort'));
    }

    public function testToStringReturnsValue(): void
    {
        $key = new HospitalCohortKey(HospitalLocation::URBAN, HospitalTier::FULL);

        self::assertSame('urban_full', (string) $key);
    }

    public function testEqualsComparesLocationAndTier(): void
    {
        $left = new HospitalCohortKey(HospitalLocation::URBAN, HospitalTier::BASIC);
        $same = new HospitalCohortKey(HospitalLocation::URBAN, HospitalTier::BASIC);
        $different = new HospitalCohortKey(HospitalLocation::RURAL, HospitalTier::BASIC);

        self::assertTrue($left->equals($same));
        self::assertFalse($left->equals($different));
    }

    public function testLegacyRuralAdvancedAliasMapsToExtended(): void
    {
        $key = HospitalCohortKey::tryFrom('rural_advanced');
        self::assertNotNull($key);
        self::assertSame('rural_extended', $key->value());
    }

    public function testProjectionCodesMapDomainEnums(): void
    {
        $key = new HospitalCohortKey(HospitalLocation::MIXED, HospitalTier::EXTENDED);

        self::assertSame(2, $key->locationProjectionCode()->value);
        self::assertSame(2, $key->tierProjectionCode()->value);
    }
}
