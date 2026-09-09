<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneClientInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneStoreInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Application\Hospital\DTO\HospitalIsochroneFetchOutcome;
use App\Allocation\Application\Hospital\HospitalGeoScope;
use App\Allocation\Application\Hospital\HospitalGeoScopeResolver;
use App\Allocation\Application\Hospital\HospitalIsochroneFetchService;
use App\Allocation\Application\Hospital\IsochroneOrigin;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use PHPUnit\Framework\TestCase;

final class HospitalIsochroneFetchServiceTest extends TestCase
{
    public function testSkipsExistingFileWhenOriginMatchesHospitalCoordinates(): void
    {
        $hospital = $this->hospital(50.1109, 8.6821);
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::never())->method('fetchDestinationIsochrones');
        $store = $this->createMock(HospitalIsochroneStoreInterface::class);
        $store->method('existsForHospital')->willReturn(true);
        $store->method('findForHospital')->willReturn(
            IsochroneOrigin::withCoordinates($this->geojson(), 50.1109, 8.6821),
        );
        $store->expects(self::never())->method('writeForHospital');

        $report = $this->service($hospital, $client, $store)->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertTrue($report->success);
        self::assertSame(1, $report->skipped);
        self::assertSame(0, $report->written);
        self::assertSame('skip', $report->rows[0][2]);
    }

    public function testFetchesWhenStoredOriginDiffersFromHospitalCoordinates(): void
    {
        $hospital = $this->hospital(51.3224, 9.5081);
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::once())->method('fetchDestinationIsochrones')
            ->with(51.3224, 9.5081)
            ->willReturn(HospitalIsochroneFetchOutcome::success($this->geojson()));
        $store = $this->createMock(HospitalIsochroneStoreInterface::class);
        $store->method('existsForHospital')->willReturn(true);
        $store->method('findForHospital')->willReturn(
            IsochroneOrigin::withCoordinates($this->geojson(), 51.3127, 9.4797),
        );
        $store->expects(self::once())->method('writeForHospital')->with(
            $hospital,
            IsochroneOrigin::withCoordinates($this->geojson(), 51.3224, 9.5081),
        );

        $report = $this->service($hospital, $client, $store)->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertSame(1, $report->written);
        self::assertSame(0, $report->skipped);
        self::assertSame('fetch', $report->rows[0][2]);
    }

    public function testFetchesWhenExistingFileHasNoOrigin(): void
    {
        $hospital = $this->hospital(50.1109, 8.6821);
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::once())->method('fetchDestinationIsochrones')
            ->willReturn(HospitalIsochroneFetchOutcome::success($this->geojson()));
        $store = $this->createMock(HospitalIsochroneStoreInterface::class);
        $store->method('existsForHospital')->willReturn(true);
        $store->method('findForHospital')->willReturn($this->geojson());
        $store->expects(self::once())->method('writeForHospital');

        $report = $this->service($hospital, $client, $store)->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertSame(1, $report->written);
        self::assertSame(0, $report->skipped);
    }

    public function testUnknownScopeFails(): void
    {
        $hospitalLookup = $this->createStub(HospitalLookupInterface::class);
        $stateLookup = $this->createStub(StateLookupInterface::class);
        $stateLookup->method('findById')->willReturn(null);
        $service = new HospitalIsochroneFetchService(
            new HospitalGeoScopeResolver(
                $hospitalLookup,
                $this->createStub(DispatchAreaLookupInterface::class),
                $stateLookup,
            ),
            $this->createStub(HospitalIsochroneClientInterface::class),
            $this->createStub(HospitalIsochroneStoreInterface::class),
        );

        $report = $service->run($this->scope(), apply: false, force: false, delayMs: 0);

        self::assertFalse($report->success);
        self::assertStringContainsString('Unknown federal state', (string) $report->error);
    }

    public function testApplyWithoutApiKeyFails(): void
    {
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(false);
        $client->expects(self::never())->method('fetchDestinationIsochrones');

        $report = $this->service(
            $this->hospital(50.1, 8.6),
            $client,
            $this->createStub(HospitalIsochroneStoreInterface::class),
        )->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertFalse($report->success);
        self::assertStringContainsString('OPENROUTESERVICE_API_KEY', (string) $report->error);
    }

    public function testDryRunDoesNotCallClient(): void
    {
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::never())->method('fetchDestinationIsochrones');
        $store = $this->createStub(HospitalIsochroneStoreInterface::class);
        $store->method('existsForHospital')->willReturn(false);

        $report = $this->service($this->hospital(50.1109, 8.6821), $client, $store)
            ->run($this->scope(), apply: false, force: false, delayMs: 0);

        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->toFetch);
        self::assertSame(0, $report->written);
        self::assertSame('fetch', $report->rows[0][2]);
        self::assertSame('Hessen', $report->scopeLabel);
    }

    public function testMissingCoordinatesAreReportedWithoutFetch(): void
    {
        $hospital = new Hospital()->setName('Ohne Koordinaten');
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::never())->method('fetchDestinationIsochrones');

        $report = $this->service($hospital, $client, $this->createStub(HospitalIsochroneStoreInterface::class))
            ->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertSame(1, $report->missingCoords);
        self::assertSame('missing-coords', $report->rows[0][2]);
    }

    public function testFailedFetchIncrementsFailedWithoutWriting(): void
    {
        $client = $this->createStub(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->method('fetchDestinationIsochrones')->willReturn(HospitalIsochroneFetchOutcome::failed());
        $store = $this->createMock(HospitalIsochroneStoreInterface::class);
        $store->method('existsForHospital')->willReturn(false);
        $store->expects(self::never())->method('writeForHospital');

        $report = $this->service($this->hospital(50.1109, 8.6821), $client, $store)
            ->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->written);
        self::assertSame('failed', $report->rows[0][2]);
    }

    public function testForceFetchesEvenWhenOriginMatches(): void
    {
        $hospital = $this->hospital(50.1109, 8.6821);
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::once())->method('fetchDestinationIsochrones')
            ->willReturn(HospitalIsochroneFetchOutcome::success($this->geojson()));
        $store = $this->createMock(HospitalIsochroneStoreInterface::class);
        $store->method('existsForHospital')->willReturn(true);
        $store->method('findForHospital')->willReturn(
            IsochroneOrigin::withCoordinates($this->geojson(), 50.1109, 8.6821),
        );
        $store->expects(self::once())->method('writeForHospital');

        $report = $this->service($hospital, $client, $store)->run($this->scope(), apply: true, force: true, delayMs: 0);

        self::assertSame(1, $report->written);
        self::assertSame(0, $report->skipped);
    }

    public function testRateLimitRetriesOnceThenAbortsWithoutWritingRemainingHospitals(): void
    {
        $first = $this->hospital(50.1109, 8.6821, 'Erste');
        $second = $this->hospital(50.2, 8.7, 'Zweite');
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::exactly(2))->method('fetchDestinationIsochrones')
            ->willReturn(HospitalIsochroneFetchOutcome::rateLimited(1));
        $store = $this->createMock(HospitalIsochroneStoreInterface::class);
        $store->method('existsForHospital')->willReturn(false);
        $store->expects(self::never())->method('writeForHospital');

        $report = $this->service([$first, $second], $client, $store)
            ->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertTrue($report->rateLimited);
        self::assertSame(0, $report->written);
        self::assertSame('rate-limited', $report->rows[0][2]);
        self::assertSame('fetch', $report->rows[1][2]);
    }

    public function testRateLimitRetryCanRecoverAndContinue(): void
    {
        $hospital = $this->hospital(50.1109, 8.6821);
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::exactly(2))->method('fetchDestinationIsochrones')
            ->willReturnOnConsecutiveCalls(
                HospitalIsochroneFetchOutcome::rateLimited(1),
                HospitalIsochroneFetchOutcome::success($this->geojson()),
            );
        $store = $this->createMock(HospitalIsochroneStoreInterface::class);
        $store->method('existsForHospital')->willReturn(false);
        $store->expects(self::once())->method('writeForHospital');

        $report = $this->service($hospital, $client, $store)->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertFalse($report->rateLimited);
        self::assertSame(1, $report->written);
        self::assertSame('fetch', $report->rows[0][2]);
    }

    /**
     * @param Hospital|list<Hospital> $hospitals
     */
    private function service(
        Hospital|array $hospitals,
        HospitalIsochroneClientInterface $client,
        HospitalIsochroneStoreInterface $store,
    ): HospitalIsochroneFetchService {
        $stateLookup = $this->createStub(StateLookupInterface::class);
        $stateLookup->method('findById')->willReturn(new State()->setName('Hessen'));
        $hospitalLookup = $this->createStub(HospitalLookupInterface::class);
        $hospitalLookup->method('findByState')->willReturn(\is_array($hospitals) ? $hospitals : [$hospitals]);

        return new HospitalIsochroneFetchService(
            new HospitalGeoScopeResolver(
                $hospitalLookup,
                $this->createStub(DispatchAreaLookupInterface::class),
                $stateLookup,
            ),
            $client,
            $store,
        );
    }

    private function scope(): HospitalGeoScope
    {
        return HospitalGeoScope::state(1);
    }

    private function hospital(float $latitude, float $longitude, string $name = 'Klinik'): Hospital
    {
        return new Hospital()
            ->setName($name)
            ->setLatitude($latitude)
            ->setLongitude($longitude);
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function geojson(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => []],
                ],
            ],
        ];
    }
}
