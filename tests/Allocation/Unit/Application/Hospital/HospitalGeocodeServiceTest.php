<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalGeocodeClientInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeMatch;
use App\Allocation\Application\Hospital\DTO\HospitalGeocodeOutcome;
use App\Allocation\Application\Hospital\HospitalGeocodeService;
use App\Allocation\Application\Hospital\HospitalGeoScope;
use App\Allocation\Application\Hospital\HospitalGeoScopeResolver;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class HospitalGeocodeServiceTest extends TestCase
{
    public function testUnknownScopeFails(): void
    {
        $report = $this->service(unknownScope: true)->run($this->scope(), apply: false, force: false, delayMs: 0);

        self::assertFalse($report->success);
        self::assertStringContainsString('Unknown federal state', (string) $report->error);
    }

    public function testApplyWithoutApiKeyFails(): void
    {
        $client = $this->createMock(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn(false);
        $client->expects(self::never())->method('geocodeAddress');

        $report = $this->service(client: $client)->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertFalse($report->success);
        self::assertStringContainsString('OPENROUTESERVICE_API_KEY', (string) $report->error);
    }

    public function testDryRunDoesNotCallClientAndSkipsExistingCoordinates(): void
    {
        $withCoords = $this->hospital('Mit Koordinaten', '34125', 'Kassel', 51.31, 9.47);
        $withoutCoords = $this->hospital('Ohne Koordinaten', '34125', 'Kassel');
        $missingAddress = $this->hospital('Ohne Adresse', '', '');
        $client = $this->createMock(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::never())->method('geocodeAddress');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $report = $this->service(
            hospitals: [$withCoords, $withoutCoords, $missingAddress],
            client: $client,
            entityManager: $entityManager,
        )->run($this->scope(), apply: false, force: false, delayMs: 0);

        self::assertTrue($report->success);
        self::assertTrue($report->dryRun);
        self::assertSame(1, $report->skipped);
        self::assertSame(1, $report->toGeocode);
        self::assertSame(1, $report->missingAddress);
        self::assertSame(0, $report->written);
        self::assertSame('skip', $report->rows[0][4]);
        self::assertSame('geocode', $report->rows[1][4]);
        self::assertSame('missing-address', $report->rows[2][4]);
        self::assertSame('Hessen', $report->scopeLabel);
    }

    public function testApplyWritesCoordinatesWithForce(): void
    {
        $hospital = $this->hospital('Klinikum Kassel', '34125', 'Kassel', 51.3127, 9.4797);
        $client = $this->createMock(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::once())->method('geocodeAddress')->with(
            'Mönchebergstraße 41-43',
            '34125',
            'Kassel',
            'DE',
        )->willReturn(HospitalGeocodeOutcome::match(new HospitalGeocodeMatch(
            latitude: 51.3224,
            longitude: 9.5081,
            label: 'Mönchebergstraße 41-43, 34125 Kassel',
            layer: 'address',
        )));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $report = $this->service(
            hospitals: [$hospital],
            client: $client,
            entityManager: $entityManager,
        )->run($this->scope(), apply: true, force: true, delayMs: 0);

        self::assertTrue($report->success);
        self::assertSame(1, $report->written);
        self::assertSame('geocode', $report->rows[0][4]);
        self::assertSame(51.3224, $hospital->getLatitude());
        self::assertSame(9.5081, $hospital->getLongitude());
    }

    public function testApplyRecordsUnusableMatchWithoutWriting(): void
    {
        $hospital = $this->hospital('Ohne Koordinaten', '34125', 'Kassel');
        $client = $this->createStub(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->method('geocodeAddress')->willReturn(HospitalGeocodeOutcome::unusableMatch());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $report = $this->service(hospitals: [$hospital], client: $client, entityManager: $entityManager)
            ->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertSame(1, $report->unusableMatch);
        self::assertSame(0, $report->written);
        self::assertNull($hospital->getLatitude());
    }

    public function testApplyRecordsFailedRequestWithoutWriting(): void
    {
        $hospital = $this->hospital('Ohne Koordinaten', '34125', 'Kassel');
        $client = $this->createStub(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->method('geocodeAddress')->willReturn(HospitalGeocodeOutcome::failed());
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $report = $this->service(hospitals: [$hospital], client: $client, entityManager: $entityManager)
            ->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertSame(1, $report->failed);
        self::assertSame(0, $report->written);
        self::assertSame('failed', $report->rows[0][4]);
        self::assertNull($hospital->getLatitude());
    }

    public function testRateLimitRetriesOnceThenAbortsAfterFlushingWrittenCoordinates(): void
    {
        $first = $this->hospital('Erste', '34125', 'Kassel');
        $second = $this->hospital('Zweite', '34125', 'Kassel');
        $client = $this->createMock(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::exactly(3))->method('geocodeAddress')
            ->willReturnOnConsecutiveCalls(
                HospitalGeocodeOutcome::match(new HospitalGeocodeMatch(
                    latitude: 51.3224,
                    longitude: 9.5081,
                    label: 'Erste',
                    layer: 'address',
                )),
                HospitalGeocodeOutcome::rateLimited(1),
                HospitalGeocodeOutcome::rateLimited(1),
            );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $report = $this->service(hospitals: [$first, $second], client: $client, entityManager: $entityManager)
            ->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertTrue($report->rateLimited);
        self::assertSame(1, $report->written);
        self::assertSame(51.3224, $first->getLatitude());
        self::assertNull($second->getLatitude());
        self::assertSame('rate-limited', $report->rows[1][4]);
    }

    public function testRateLimitRetryCanRecoverAndContinue(): void
    {
        $hospital = $this->hospital('Ohne Koordinaten', '34125', 'Kassel');
        $client = $this->createMock(HospitalGeocodeClientInterface::class);
        $client->method('hasApiKey')->willReturn(true);
        $client->expects(self::exactly(2))->method('geocodeAddress')
            ->willReturnOnConsecutiveCalls(
                HospitalGeocodeOutcome::rateLimited(1),
                HospitalGeocodeOutcome::match(new HospitalGeocodeMatch(
                    latitude: 51.3224,
                    longitude: 9.5081,
                    label: 'Klinik',
                    layer: 'address',
                )),
            );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $report = $this->service(hospitals: [$hospital], client: $client, entityManager: $entityManager)
            ->run($this->scope(), apply: true, force: false, delayMs: 0);

        self::assertFalse($report->rateLimited);
        self::assertSame(1, $report->written);
        self::assertSame(51.3224, $hospital->getLatitude());
        self::assertSame('geocode', $report->rows[0][4]);
    }

    /**
     * @param list<Hospital> $hospitals
     */
    private function service(
        bool $unknownScope = false,
        array $hospitals = [],
        ?HospitalGeocodeClientInterface $client = null,
        ?EntityManagerInterface $entityManager = null,
    ): HospitalGeocodeService {
        $stateLookup = $this->createStub(StateLookupInterface::class);
        $stateLookup->method('findById')->willReturn($unknownScope ? null : new State()->setName('Hessen'));
        $hospitalLookup = $this->createStub(HospitalLookupInterface::class);
        $hospitalLookup->method('findByState')->willReturn($hospitals);

        return new HospitalGeocodeService(
            new HospitalGeoScopeResolver(
                $hospitalLookup,
                $this->createStub(DispatchAreaLookupInterface::class),
                $stateLookup,
            ),
            $client ?? $this->createStub(HospitalGeocodeClientInterface::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
        );
    }

    private function scope(): HospitalGeoScope
    {
        return HospitalGeoScope::state(1);
    }

    private function hospital(
        string $name,
        string $postalCode,
        string $city,
        ?float $latitude = null,
        ?float $longitude = null,
    ): Hospital {
        $hospital = new Hospital()
            ->setName($name)
            ->setLatitude($latitude)
            ->setLongitude($longitude);
        $hospital->getAddress()
            ->setStreet('' === $postalCode && '' === $city ? '' : 'Mönchebergstraße 41-43')
            ->setPostalCode($postalCode)
            ->setCity($city)
            ->setCountry('Deutschland');

        return $hospital;
    }
}
