<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\PreparedLocation;

use App\Allocation\Application\Contracts\HospitalGeocodeClientInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneClientInterface;
use App\Allocation\Application\Contracts\HospitalIsochroneProviderInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeMatch;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeOutcome;
use App\Allocation\Application\Hospital\DTO\HospitalIsochroneFetchOutcome;
use App\Allocation\Application\Hospital\HospitalGeocodeAddress;
use App\Allocation\Application\Hospital\IsochroneOrigin;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocationPreparer;
use App\Allocation\Application\Hospital\PreparedLocation\PrepareLocationRequest;
use App\Allocation\Domain\Entity\Address;
use App\Allocation\Domain\Entity\Hospital;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PreparedLocationPreparerTest extends TestCase
{
    private InMemoryPreparedLocationStore $store;

    #[\Override]
    protected function setUp(): void
    {
        $this->store = new InMemoryPreparedLocationStore();
    }

    public function testAddressIsGeocodedThenIsochronesAreFetchedForTheMatch(): void
    {
        $geocode = $this->geocodeClient();
        $geocode->expects(self::once())->method('geocodeAddress')
            ->with('Musterstraße 1', '12345', 'Musterstadt', 'DE')
            ->willReturn(HospitalGeocodeOutcome::match(new HospitalGeocodeMatch(50.5, 8.25, 'Musterstraße 1, Musterstadt', 'address')));
        $isochrones = $this->isochroneClient();
        $isochrones->expects(self::once())->method('fetchDestinationIsochrones')
            ->with(50.5, 8.25)
            ->willReturn(HospitalIsochroneFetchOutcome::success($this->geojson()));

        $report = $this->preparer($geocode, $isochrones)->run($this->addressRequest(), apply: true, force: false, delayMs: 0);

        self::assertTrue($report->success, (string) $report->error);
        self::assertSame(2, $report->requestsRequired);
        self::assertSame(2, $report->requestsExecuted);
        $stored = $this->store->find('musterstadt');
        self::assertInstanceOf(PreparedLocation::class, $stored);
        self::assertSame('Klinikum Musterstadt', $stored->label);
        self::assertSame(50.5, $stored->latitude);
        self::assertSame(8.25, $stored->longitude);
        self::assertSame(['lat' => 50.5, 'lng' => 8.25], $stored->isochrones['properties']['origin'] ?? null);
        self::assertSame('Musterstraße 1, 12345 Musterstadt', $stored->address?->display());
        self::assertSame('2026-10-28T12:00:00+00:00', $stored->preparedAt->format(\DATE_ATOM));
    }

    public function testManualPinSkipsGeocoding(): void
    {
        $geocode = $this->geocodeClient();
        $geocode->expects(self::never())->method('geocodeAddress');
        $isochrones = $this->isochroneClient();
        $isochrones->expects(self::once())->method('fetchDestinationIsochrones')
            ->with(50.75, 9.0)
            ->willReturn(HospitalIsochroneFetchOutcome::success($this->geojson()));

        $report = $this->preparer($geocode, $isochrones)->run(
            new PrepareLocationRequest(slug: 'pin', latitude: 50.75, longitude: 9.0),
            apply: true,
            force: false,
            delayMs: 0,
        );

        self::assertTrue($report->success);
        self::assertSame(1, $report->requestsExecuted);
        self::assertSame('pin', $this->store->find('pin')?->label);
    }

    public function testExistingHospitalWithMatchingIsochroneFileNeedsNoNetworkAndNoApiKey(): void
    {
        $hospital = new Hospital()->setName('Klinik Musterdorf')->setLatitude(50.1)->setLongitude(8.6)
            ->setAddress(new Address()->setStreet('Dorfweg 2')->setPostalCode('54321')->setCity('Musterdorf'));
        $geocode = $this->geocodeClient(hasKey: false);
        $geocode->expects(self::never())->method('geocodeAddress');
        $isochrones = $this->isochroneClient(hasKey: false);
        $isochrones->expects(self::never())->method('fetchDestinationIsochrones');
        $provider = $this->createStub(HospitalIsochroneProviderInterface::class);
        $provider->method('findForHospital')->willReturn(IsochroneOrigin::withCoordinates($this->geojson(), 50.1, 8.6));

        $report = $this->preparer($geocode, $isochrones, $hospital, $provider)->run(
            new PrepareLocationRequest(slug: 'musterdorf', hospitalId: 4),
            apply: true,
            force: false,
            delayMs: 0,
        );

        self::assertTrue($report->success, (string) $report->error);
        self::assertSame(0, $report->requestsRequired);
        self::assertSame('reuse', $report->steps[2][2]);
        $stored = $this->store->find('musterdorf');
        self::assertInstanceOf(PreparedLocation::class, $stored);
        self::assertSame('Klinik Musterdorf', $stored->label);
        self::assertSame('Dorfweg 2, 54321 Musterdorf', $stored->address?->display());
    }

    public function testExistingHospitalWithStaleIsochroneFileFetchesNewIsochrones(): void
    {
        $hospital = new Hospital()->setName('Klinik Musterdorf')->setLatitude(50.1)->setLongitude(8.6);
        $isochrones = $this->isochroneClient();
        $isochrones->expects(self::once())->method('fetchDestinationIsochrones')->with(50.1, 8.6)
            ->willReturn(HospitalIsochroneFetchOutcome::success($this->geojson()));
        $provider = $this->createStub(HospitalIsochroneProviderInterface::class);
        $provider->method('findForHospital')->willReturn(IsochroneOrigin::withCoordinates($this->geojson(), 50.2, 8.6));

        $report = $this->preparer($this->geocodeStub(), $isochrones, $hospital, $provider)->run(
            new PrepareLocationRequest(slug: 'musterdorf', hospitalId: 4),
            apply: true,
            force: false,
            delayMs: 0,
        );

        self::assertTrue($report->success);
        self::assertSame(1, $report->requestsExecuted);
    }

    public function testHospitalWithoutCoordinatesFails(): void
    {
        $report = $this->preparer($this->geocodeStub(), $this->isochroneStub(), new Hospital()->setName('Ohne'))->run(
            new PrepareLocationRequest(slug: 'ohne', hospitalId: 4),
            apply: true,
            force: false,
            delayMs: 0,
        );

        self::assertFalse($report->success);
        self::assertStringContainsString('no coordinates', (string) $report->error);
    }

    public function testDryRunCountsRequestsWithoutCallingOrWriting(): void
    {
        $geocode = $this->geocodeClient();
        $geocode->expects(self::never())->method('geocodeAddress');
        $isochrones = $this->isochroneClient();
        $isochrones->expects(self::never())->method('fetchDestinationIsochrones');

        $report = $this->preparer($geocode, $isochrones)->run($this->addressRequest(), apply: false, force: false, delayMs: 0);

        self::assertTrue($report->success);
        self::assertTrue($report->dryRun);
        self::assertSame(2, $report->requestsRequired);
        self::assertSame('geocode', $report->steps[1][2]);
        self::assertSame('fetch', $report->steps[2][2]);
        self::assertFalse($this->store->exists('musterstadt'));
    }

    public function testExistingSlugNeedsForce(): void
    {
        $this->store->save(new PreparedLocation('musterstadt', 'Alt', null, 50.0, 8.0, IsochroneOrigin::withCoordinates($this->geojson(), 50.0, 8.0), new \DateTimeImmutable('2026-01-01')));
        $isochrones = $this->isochroneStub();
        $isochrones->method('fetchDestinationIsochrones')->willReturn(HospitalIsochroneFetchOutcome::success($this->geojson()));
        $geocode = $this->geocodeStub();
        $geocode->method('geocodeAddress')->willReturn(HospitalGeocodeOutcome::match(new HospitalGeocodeMatch(50.5, 8.25, 'x', 'address')));
        $preparer = $this->preparer($geocode, $isochrones);

        $refused = $preparer->run($this->addressRequest(), apply: true, force: false, delayMs: 0);
        $replaced = $preparer->run($this->addressRequest(), apply: true, force: true, delayMs: 0);

        self::assertFalse($refused->success);
        self::assertStringContainsString('already exists', (string) $refused->error);
        self::assertTrue($replaced->success);
        self::assertSame('Klinikum Musterstadt', $this->store->find('musterstadt')?->label);
    }

    public function testApplyWithoutApiKeyFailsBeforeAnyRequest(): void
    {
        $geocode = $this->geocodeClient(hasKey: false);
        $geocode->expects(self::never())->method('geocodeAddress');

        $report = $this->preparer($geocode, $this->isochroneStub(hasKey: false))->run($this->addressRequest(), apply: true, force: false, delayMs: 0);

        self::assertFalse($report->success);
        self::assertStringContainsString('OPENROUTESERVICE_API_KEY', (string) $report->error);
    }

    public function testUnusableGeocodeMatchStoresNothing(): void
    {
        $geocode = $this->geocodeStub();
        $geocode->method('geocodeAddress')->willReturn(HospitalGeocodeOutcome::unusableMatch());
        $isochrones = $this->isochroneClient();
        $isochrones->expects(self::never())->method('fetchDestinationIsochrones');

        $report = $this->preparer($geocode, $isochrones)->run($this->addressRequest(), apply: true, force: false, delayMs: 0);

        self::assertFalse($report->success);
        self::assertSame('unusable-match', $report->steps[1][2]);
        self::assertFalse($this->store->exists('musterstadt'));
    }

    public function testRateLimitIsRetriedOnceThenReported(): void
    {
        $geocode = $this->geocodeStub();
        $geocode->method('geocodeAddress')->willReturn(HospitalGeocodeOutcome::match(new HospitalGeocodeMatch(50.5, 8.25, 'Musterstraße 1, Musterstadt', 'address')));
        $isochrones = $this->isochroneClient();
        $isochrones->expects(self::exactly(2))->method('fetchDestinationIsochrones')
            ->willReturn(HospitalIsochroneFetchOutcome::rateLimited(1));

        $report = $this->preparer($geocode, $isochrones)->run($this->addressRequest(), apply: true, force: false, delayMs: 0);

        self::assertFalse($report->success);
        self::assertTrue($report->rateLimited);
        self::assertSame('rate-limited', $report->steps[2][2]);
        self::assertFalse($this->store->exists('musterstadt'));
    }

    public function testInvalidRequestFailsWithoutLookupsOrRequests(): void
    {
        $geocode = $this->geocodeClient();
        $geocode->expects(self::never())->method('geocodeAddress');

        $report = $this->preparer($geocode, $this->isochroneStub())->run(
            new PrepareLocationRequest(slug: 'pin', latitude: 50.5),
            apply: true,
            force: false,
            delayMs: 0,
        );

        self::assertFalse($report->success);
        self::assertSame('Pass both --lat and --lng.', $report->error);
        self::assertSame([], $report->steps);
    }

    public function testUnknownHospitalFails(): void
    {
        $report = $this->preparer($this->geocodeStub(), $this->isochroneStub(), hospital: null)->run(
            new PrepareLocationRequest(slug: 'musterdorf', hospitalId: 999),
            apply: false,
            force: false,
            delayMs: 0,
        );

        self::assertFalse($report->success);
        self::assertTrue($report->dryRun);
        self::assertSame('Unknown hospital id 999.', $report->error);
    }

    public function testGeocodeRateLimitIsRetriedOnceThenReported(): void
    {
        $geocode = $this->geocodeClient();
        $geocode->expects(self::exactly(2))->method('geocodeAddress')
            ->willReturn(HospitalGeocodeOutcome::rateLimited(1));
        $isochrones = $this->isochroneClient();
        $isochrones->expects(self::never())->method('fetchDestinationIsochrones');

        $report = $this->preparer($geocode, $isochrones)->run($this->addressRequest(), apply: true, force: false, delayMs: 0);

        self::assertFalse($report->success);
        self::assertTrue($report->rateLimited);
        self::assertSame('OpenRouteService rate limit reached. Re-run later.', $report->error);
        self::assertSame('rate-limited', $report->steps[1][2]);
        self::assertSame(1, $report->requestsExecuted);
    }

    public function testGeocodeRetrySucceedsAfterOneRateLimit(): void
    {
        $geocode = $this->geocodeClient();
        $geocode->expects(self::exactly(2))->method('geocodeAddress')->willReturnOnConsecutiveCalls(
            HospitalGeocodeOutcome::rateLimited(1),
            HospitalGeocodeOutcome::match(new HospitalGeocodeMatch(50.5, 8.25, 'Musterstraße 1, Musterstadt', 'address')),
        );
        $isochrones = $this->isochroneClient();
        $isochrones->expects(self::once())->method('fetchDestinationIsochrones')->with(50.5, 8.25)
            ->willReturn(HospitalIsochroneFetchOutcome::success($this->geojson()));

        $report = $this->preparer($geocode, $isochrones)->run($this->addressRequest(), apply: true, force: false, delayMs: 0);

        self::assertTrue($report->success, (string) $report->error);
        self::assertSame(50.5, $this->store->find('musterstadt')?->latitude);
    }

    public function testRequestValidation(): void
    {
        $address = new HospitalGeocodeAddress('Musterstraße 1', '12345', 'Musterstadt', 'DE');

        self::assertStringContainsString('Invalid slug', (string) new PrepareLocationRequest(slug: 'Muster Stadt', address: $address)->sourceError());
        self::assertStringContainsString('both --lat and --lng', (string) new PrepareLocationRequest(slug: 'a', latitude: 50.0)->sourceError());
        self::assertStringContainsString('not both', (string) new PrepareLocationRequest(slug: 'a', address: $address, hospitalId: 1)->sourceError());
        self::assertStringContainsString('Specify', (string) new PrepareLocationRequest(slug: 'a')->sourceError());
        self::assertStringContainsString('Specify', (string) new PrepareLocationRequest(slug: 'a', address: new HospitalGeocodeAddress('', '12345', '', 'DE'))->sourceError());
        self::assertStringContainsString('Latitude', (string) new PrepareLocationRequest(slug: 'a', latitude: 91.0, longitude: 8.0)->sourceError());
        self::assertStringContainsString('Longitude', (string) new PrepareLocationRequest(slug: 'a', latitude: 50.0, longitude: -180.5)->sourceError());
        self::assertNull(new PrepareLocationRequest(slug: 'a', address: $address)->sourceError());
        self::assertNull(new PrepareLocationRequest(slug: 'a', address: $address, latitude: 50.0, longitude: 8.0)->sourceError());
    }

    private function addressRequest(): PrepareLocationRequest
    {
        return new PrepareLocationRequest(
            slug: 'musterstadt',
            label: 'Klinikum Musterstadt',
            address: new HospitalGeocodeAddress('Musterstraße 1', '12345', 'Musterstadt', 'DE'),
        );
    }

    private function preparer(
        HospitalGeocodeClientInterface $geocode,
        HospitalIsochroneClientInterface $isochrones,
        ?Hospital $hospital = null,
        ?HospitalIsochroneProviderInterface $provider = null,
    ): PreparedLocationPreparer {
        $lookup = $this->createStub(HospitalLookupInterface::class);
        $lookup->method('findById')->willReturn($hospital);

        return new PreparedLocationPreparer(
            $lookup,
            $geocode,
            $isochrones,
            $provider ?? $this->createStub(HospitalIsochroneProviderInterface::class),
            $this->store,
            new MockClock('2026-10-28 12:00:00', 'UTC'),
        );
    }

    /**
     * @return HospitalGeocodeClientInterface&MockObject
     */
    private function geocodeClient(bool $hasKey = true): HospitalGeocodeClientInterface
    {
        $client = $this->createMock(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn($hasKey);

        return $client;
    }

    /**
     * @return HospitalIsochroneClientInterface&MockObject
     */
    private function isochroneClient(bool $hasKey = true): HospitalIsochroneClientInterface
    {
        $client = $this->createMock(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn($hasKey);

        return $client;
    }

    /**
     * @return HospitalGeocodeClientInterface&Stub
     */
    private function geocodeStub(bool $hasKey = true): HospitalGeocodeClientInterface
    {
        $client = $this->createStub(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn($hasKey);

        return $client;
    }

    /**
     * @return HospitalIsochroneClientInterface&Stub
     */
    private function isochroneStub(bool $hasKey = true): HospitalIsochroneClientInterface
    {
        $client = $this->createStub(HospitalIsochroneClientInterface::class);
        $client->method('hasApiKey')->willReturn($hasKey);

        return $client;
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function geojson(): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => ['value' => 600], 'geometry' => ['type' => 'Polygon', 'coordinates' => []]],
            ],
        ];
    }
}
