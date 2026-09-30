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
            'closureFrom' => '2026-03-15',
            'closureTo' => '2026-03-01',
            'closureEventTypes' => ['cluster', 'invalid', 'single', 'cluster'],
            'closureHospitals' => ['12', '12', '0', 'invalid', '7'],
        ]);

        $filter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);

        self::assertSame([3], $filter->departmentIds);
        self::assertSame([8, 9], $filter->specialityIds);
        self::assertSame(['emergency', 'other'], $filter->careLevels);
        self::assertSame(['no_bed_capacity', 'technical_fault'], $filter->reasons);
        self::assertSame(['Unit A', 'Unit B'], $filter->closureUnits);
        self::assertSame('2026-03-01', $filter->fromDate);
        self::assertSame('2026-03-15', $filter->toDate);
        self::assertSame(['cluster', 'single'], $filter->eventTypes);
        self::assertSame([12, 7], $filter->hospitalIds);
        self::assertSame('2026-03-01 00:00:00', $filter->periodFrom()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-16 00:00:00', $filter->periodToExclusive()?->format('Y-m-d H:i:s'));
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
            'closureFrom' => 'not-a-date',
            'closureTo' => '2026-02-31',
            'closureEventTypes' => 'group',
            'closureHospitals' => '18',
        ]);

        $filter = ClosureAnalyticsFilterRequestResolver::fromRequest($request);

        self::assertSame([4], $filter->departmentIds);
        self::assertSame([5], $filter->specialityIds);
        self::assertSame(['inpatient'], $filter->careLevels);
        self::assertSame(['operating_room_notice'], $filter->reasons);
        self::assertSame(['Local unit'], $filter->closureUnits);
        self::assertNull($filter->fromDate);
        self::assertNull($filter->toDate);
        self::assertSame(['group'], $filter->eventTypes);
        self::assertSame([18], $filter->hospitalIds);
        self::assertSame(
            ['scope' => 'public', 'period' => 'all_time'],
            ClosureAnalyticsFilterRequestResolver::withoutFilters($request->query->all()),
        );
    }

    public function testItIgnoresEmptyDateFields(): void
    {
        $filter = ClosureAnalyticsFilterRequestResolver::fromRequest(new Request([
            'closureFrom' => '',
            'closureTo' => '   ',
            'closureDepartments' => ['4'],
        ]));

        self::assertNull($filter->fromDate);
        self::assertNull($filter->toDate);
        self::assertSame([4], $filter->departmentIds);
    }
}
