<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\UI\Http\Controller\ClosureAnalyticsFilterRequestResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ClosureAnalyticsFilterRequestResolverTest extends TestCase
{
    public function testItNormalizesMultiValueFilters(): void
    {
        $request = new Request([
            'closureDepartments' => ['3', '3', '-1', 'invalid'],
            'closureSpecialities' => ['8', '0', '9'],
            'closureCareLevels' => ['emergency', 'other', 'invalid', 'emergency'],
            'closureReasons' => ['no_bed_capacity', 'invalid', 'technical_fault', 'no_bed_capacity'],
            'closureUnits' => [' Unit A ', '', 'Unit A', 'Unit B'],
        ]);

        $filter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);

        self::assertSame([3], $filter->departmentIds);
        self::assertSame([8, 9], $filter->specialityIds);
        self::assertSame(['emergency', 'other'], $filter->careLevels);
        self::assertSame(['no_bed_capacity', 'technical_fault'], $filter->reasons);
        self::assertSame(['Unit A', 'Unit B'], $filter->closureUnits);
    }

    public function testItAcceptsScalarQueryValuesAndRemovesOnlyClosureFilters(): void
    {
        $request = new Request([
            'scope' => 'public',
            'period' => 'all_time',
            'closureDepartments' => '4',
            'closureSpecialities' => '5',
            'closureCareLevels' => 'inpatient',
            'closureReasons' => 'operating_room_notice',
            'closureUnits' => 'Local unit',
        ]);

        $filter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);

        self::assertSame([4], $filter->departmentIds);
        self::assertSame([5], $filter->specialityIds);
        self::assertSame(['inpatient'], $filter->careLevels);
        self::assertSame(['operating_room_notice'], $filter->reasons);
        self::assertSame(['Local unit'], $filter->closureUnits);
        self::assertSame(
            ['scope' => 'public', 'period' => 'all_time'],
            ClosureAnalyticsFilterRequestResolver::withoutFilters($request->query->all()),
        );
    }
}
