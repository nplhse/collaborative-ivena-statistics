<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application;

use App\Statistics\Application\Cohort\HospitalCohortResolver;
use App\Statistics\Application\Cohort\HospitalCohortType;
use PHPUnit\Framework\TestCase;

final class HospitalCohortResolverTest extends TestCase
{
    public function testResolvesUrbanAdvancedToUrbanAndExtendedFullTiers(): void
    {
        $cohort = (new HospitalCohortResolver())->resolve(HospitalCohortType::UrbanAdvanced);

        self::assertSame([1], $cohort->locationCodeValues());
        self::assertSame([2, 3], $cohort->tierCodeValues());
    }
}
