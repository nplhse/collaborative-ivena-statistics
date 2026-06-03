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
        self::assertSame('mixed_extended', (new HospitalCohortKey(HospitalLocation::MIXED, HospitalTier::EXTENDED))->value());
    }
}
