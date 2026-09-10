<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\IsochroneOriginMap;

use App\Allocation\Application\Contracts\HospitalIsochroneProviderInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Domain\Entity\Hospital;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IsochroneOriginMap\Dto\IsochroneOriginBandQueryResult;
use App\Statistics\Application\IsochroneOriginMap\IsochroneOriginBandQueryInterface;
use App\Statistics\Application\IsochroneOriginMap\IsochroneOriginHeatmapAssembler;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class IsochroneOriginHeatmapAssemblerTest extends TestCase
{
    public function testReturnsNullWhenScopeIsNotASingleHospital(): void
    {
        $query = $this->createMock(IsochroneOriginBandQueryInterface::class);
        $query->expects(self::never())->method('fetch');

        $assembler = $this->assembler($query);
        $filter = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::AllTime);

        self::assertNull($assembler->build(
            $filter,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
        ));
    }

    public function testReturnsNullWhenHospitalCannotBeFound(): void
    {
        $query = $this->createMock(IsochroneOriginBandQueryInterface::class);
        $query->expects(self::never())->method('fetch');

        $lookup = $this->createStub(HospitalLookupInterface::class);
        $lookup->method('findById')->willReturn(null);
        $provider = $this->createStub(HospitalIsochroneProviderInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $assembler = new IsochroneOriginHeatmapAssembler($lookup, $provider, $query, $translator);

        self::assertNull($assembler->build(
            $this->hospitalFilter(),
            new StatisticsScopeCriteria([42]),
            new StatisticsPeriodBounds(null),
        ));
    }

    public function testReturnsNullWhenHospitalHasNoCoordinates(): void
    {
        $hospital = $this->hospital(latitude: null, longitude: null);
        $query = $this->createMock(IsochroneOriginBandQueryInterface::class);
        $query->expects(self::never())->method('fetch');

        $assembler = $this->assembler($query, hospital: $hospital);

        self::assertNull($assembler->build(
            $this->hospitalFilter(),
            new StatisticsScopeCriteria([42]),
            new StatisticsPeriodBounds(null),
        ));
    }

    public function testReturnsNullWhenIsochronesAreMissing(): void
    {
        $query = $this->createMock(IsochroneOriginBandQueryInterface::class);
        $query->expects(self::never())->method('fetch');

        $assembler = $this->assembler($query, catalog: null);

        self::assertNull($assembler->build(
            $this->hospitalFilter(),
            new StatisticsScopeCriteria([42]),
            new StatisticsPeriodBounds(null),
        ));
    }

    public function testBuildsBandsWithRelativeIntensityAndUnmappedCounts(): void
    {
        $query = $this->createStub(IsochroneOriginBandQueryInterface::class);
        $query->method('fetch')->willReturn(new IsochroneOriginBandQueryResult([
            '10' => 1,
            '20' => 3,
            'unknown' => 2,
            'beyond_max' => 4,
        ]));

        $assembler = $this->assembler($query, catalog: $this->catalog());
        $view = $assembler->build(
            $this->hospitalFilter(),
            new StatisticsScopeCriteria([42]),
            new StatisticsPeriodBounds(null),
        );

        self::assertNotNull($view);
        self::assertSame('Test Hospital', $view->hospitalName);
        self::assertSame(50.11, $view->latitude);
        self::assertSame(8.68, $view->longitude);
        self::assertSame(2, $view->unknownCount);
        self::assertSame(4, $view->beyondMaxCount);
        self::assertSame(10, $view->totalCount);
        self::assertSame(3, $view->maxBandCount);
        self::assertCount(2, $view->bands);
        self::assertSame(10, $view->bands[0]->minutes);
        self::assertSame(1, $view->bands[0]->count);
        self::assertEqualsWithDelta(0.1, $view->bands[0]->share, 0.0001);
        self::assertEqualsWithDelta(1 / 3, $view->bands[0]->intensity, 0.0001);
        self::assertSame('stats.isochrone_origin_map.band 0-10', $view->bands[0]->label);
        self::assertSame(20, $view->bands[1]->minutes);
        self::assertSame(3, $view->bands[1]->count);
        self::assertSame(1.0, $view->bands[1]->intensity);
        self::assertSame('stats.isochrone_origin_map.band 10-20', $view->bands[1]->label);
        self::assertArrayHasKey('hospital', $view->mapPayload());
        self::assertArrayHasKey('bands', $view->mapPayload());
        self::assertSame('Test Hospital', $view->mapPayload()['hospital']['name']);
        self::assertCount(2, $view->mapPayload()['bands']);
    }

    public function testReturnsNullWhenCatalogHasNoTenMinuteGeometries(): void
    {
        $query = $this->createMock(IsochroneOriginBandQueryInterface::class);
        $query->expects(self::never())->method('fetch');

        $assembler = $this->assembler($query, catalog: [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 300],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[8.6, 50.0], [8.7, 50.0], [8.7, 50.1], [8.6, 50.1], [8.6, 50.0]]]],
                ],
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 90],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[8.6, 50.0], [8.7, 50.0], [8.7, 50.1], [8.6, 50.1], [8.6, 50.0]]]],
                ],
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                ],
            ],
        ]);

        self::assertNull($assembler->build(
            $this->hospitalFilter(),
            new StatisticsScopeCriteria([42]),
            new StatisticsPeriodBounds(null),
        ));
    }

    public function testForwardsIndicationIdsToTheBandQuery(): void
    {
        $query = $this->createMock(IsochroneOriginBandQueryInterface::class);
        $query->expects(self::once())
            ->method('fetch')
            ->with(
                self::isNull(),
                self::isNull(),
                self::isInstanceOf(StatisticsScopeCriteria::class),
                [7, 8],
            )
            ->willReturn(new IsochroneOriginBandQueryResult(['10' => 2]));

        $assembler = $this->assembler($query, catalog: $this->catalog());
        $view = $assembler->build(
            $this->hospitalFilter(),
            new StatisticsScopeCriteria([42]),
            new StatisticsPeriodBounds(null),
            [7, 8],
        );

        self::assertNotNull($view);
        self::assertSame(2, $view->bands[0]->count);
    }

    /**
     * @param array{type: string, features: list<array<string, mixed>>, properties?: array<string, mixed>}|null $catalog
     */
    private function assembler(
        IsochroneOriginBandQueryInterface $query,
        ?Hospital $hospital = null,
        ?array $catalog = null,
    ): IsochroneOriginHeatmapAssembler {
        $hospital ??= $this->hospital();
        $lookup = $this->createStub(HospitalLookupInterface::class);
        $lookup->method('findById')->willReturn($hospital);

        $provider = $this->createStub(HospitalIsochroneProviderInterface::class);
        $provider->method('findForHospital')->willReturn($catalog);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []): string {
                if (isset($parameters['from'], $parameters['to'])) {
                    return $id.' '.$parameters['from'].'-'.$parameters['to'];
                }

                return $id;
            },
        );

        return new IsochroneOriginHeatmapAssembler($lookup, $provider, $query, $translator);
    }

    private function hospital(?float $latitude = 50.11, ?float $longitude = 8.68): Hospital
    {
        $hospital = $this->createStub(Hospital::class);
        $hospital->method('getLatitude')->willReturn($latitude);
        $hospital->method('getLongitude')->willReturn($longitude);
        $hospital->method('getName')->willReturn('Test Hospital');

        return $hospital;
    }

    private function hospitalFilter(): StatisticsFilter
    {
        return new StatisticsFilter(StatisticsFilterScope::Hospital, 42, null, StatisticsFilterPeriod::AllTime);
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function catalog(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[8.6, 50.0], [8.7, 50.0], [8.7, 50.1], [8.6, 50.1], [8.6, 50.0]]]],
                ],
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 1200],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[8.5, 49.9], [8.8, 49.9], [8.8, 50.2], [8.5, 50.2], [8.5, 49.9]]]],
                ],
            ],
        ];
    }
}
