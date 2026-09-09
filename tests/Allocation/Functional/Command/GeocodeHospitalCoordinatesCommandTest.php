<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Application\Hospital\HospitalGeocodeService;
use App\Allocation\Application\Hospital\HospitalGeoScopeResolver;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\AddressFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Http\OpenRouteServiceGeocodeClient;
use App\Allocation\UI\Console\Command\GeocodeHospitalCoordinatesCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class GeocodeHospitalCoordinatesCommandTest extends KernelTestCase
{
    use Factories;

    public function testMissingScopeExitsWithFailure(): void
    {
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Specify exactly one of', $tester->getDisplay());
    }

    public function testMultipleScopesExitWithFailure(): void
    {
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--hospital-id' => 1, '--state-id' => 1]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Specify exactly one of', $tester->getDisplay());
    }

    public function testUnknownHospitalExitsWithFailure(): void
    {
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--hospital-id' => 99999]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Unknown hospital', $tester->getDisplay());
    }

    public function testUnknownStateExitsWithFailure(): void
    {
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--state-id' => 99999]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Unknown federal state', $tester->getDisplay());
    }

    public function testApplyWithoutApiKeyExitsWithFailure(): void
    {
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $stateId = $state->getId();
        self::assertNotNull($stateId);
        $tester = $this->createTester($this->failingHttpClient(), '');

        $status = $tester->execute(['--state-id' => $stateId, '--apply' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('OPENROUTESERVICE_API_KEY', $tester->getDisplay());
    }

    public function testApplyAndDryRunWarnsThatApplyTakesPrecedence(): void
    {
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--state-id' => 99999, '--apply' => true, '--dry-run' => true]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Both --apply and --dry-run were passed', $tester->getDisplay());
    }

    public function testDryRunDoesNotCallOpenRouteServiceOrWriteCoordinates(): void
    {
        $hospitals = $this->seedHospitals();
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--state-id' => $hospitals['stateId']]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Mit Koordinaten', $display);
        self::assertStringContainsString('Ohne Koordinaten', $display);
        self::assertStringContainsString('Ohne Adresse', $display);
        self::assertStringNotContainsString('Bayern Klinik', $display);
        self::assertStringContainsString('skip', $display);
        self::assertStringContainsString('geocode', $display);
        self::assertStringContainsString('missing-address', $display);
        self::assertStringContainsString('OpenRouteService requests required', $display);
        self::assertNull($this->reload($hospitals['withoutCoords'])->getLatitude());
        self::assertSame(50.1109, $this->reload($hospitals['withCoords'])->getLatitude());
    }

    public function testApplyWritesCoordinatesForHospitalsWithoutCoordsIncludingNonParticipating(): void
    {
        $hospitals = $this->seedHospitals();
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return $this->successResponse();
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $requests);
        self::assertSame(50.1109, $this->reload($hospitals['withCoords'])->getLatitude());
        $withoutCoords = $this->reload($hospitals['withoutCoords']);
        self::assertSame(51.3224, $withoutCoords->getLatitude());
        self::assertSame(9.5081, $withoutCoords->getLongitude());
        self::assertNull($this->reload($hospitals['missingAddress'])->getLatitude());
        self::assertSame(48.1, $this->reload($hospitals['otherState'])->getLatitude());
    }

    public function testParticipatingOnlySkipsNonParticipatingHospitals(): void
    {
        $hospitals = $this->seedHospitals();
        $httpClient = new MockHttpClient(static function (): MockResponse {
            Assert::fail('OpenRouteService must not be called for participating-only when remaining hospitals already have coordinates.');
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--participating-only' => true,
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringNotContainsString('Ohne Koordinaten', $tester->getDisplay());
        self::assertNull($this->reload($hospitals['withoutCoords'])->getLatitude());
    }

    public function testHospitalScopeGeocodesNonParticipatingHospital(): void
    {
        $hospitals = $this->seedHospitals();
        $hospitalId = $hospitals['withoutCoords']->getId();
        self::assertNotNull($hospitalId);
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return $this->successResponse();
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--hospital-id' => $hospitalId,
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $requests);
        self::assertSame(51.3224, $this->reload($hospitals['withoutCoords'])->getLatitude());
        self::assertSame(50.1109, $this->reload($hospitals['withCoords'])->getLatitude());
    }

    public function testDispatchAreaScopeDoesNotGeocodeOtherAreas(): void
    {
        $hospitals = $this->seedHospitals();
        $dispatchAreaId = $hospitals['withCoords']->getDispatchArea()?->getId();
        self::assertNotNull($dispatchAreaId);
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return $this->successResponse();
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--dispatch-area-id' => $dispatchAreaId,
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $requests);
        self::assertSame(51.3224, $this->reload($hospitals['withoutCoords'])->getLatitude());
        self::assertSame(48.1, $this->reload($hospitals['otherState'])->getLatitude());
    }

    public function testApplySkipsExistingCoordinatesUnlessForced(): void
    {
        $hospitals = $this->seedHospitals();
        $requests = (object) ['count' => 0];
        $httpClient = new MockHttpClient(function () use ($requests): MockResponse {
            ++$requests->count;

            return new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [8.7, 50.2]],
                        'properties' => [
                            'layer' => 'venue',
                            'label' => 'Klinik',
                            'country_a' => 'DEU',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $skip = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $skip);
        self::assertSame(1, $requests->count);
        self::assertSame(50.1109, $this->reload($hospitals['withCoords'])->getLatitude());

        $force = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--force' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $force);
        self::assertSame(3, $requests->count);
        self::assertSame(50.2, $this->reload($hospitals['withCoords'])->getLatitude());
        self::assertSame(50.2, $this->reload($hospitals['withoutCoords'])->getLatitude());
    }

    public function testApplyWarnsWhenMatchesAreUnusableOrFailed(): void
    {
        $hospitals = $this->seedHospitals();
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => ['type' => 'Point', 'coordinates' => [9.4797, 51.3127]],
                        'properties' => [
                            'layer' => 'locality',
                            'label' => 'Kassel',
                            'country_a' => 'DEU',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('unusable match(es)', $tester->getDisplay());
        self::assertNull($this->reload($hospitals['withoutCoords'])->getLatitude());
    }

    public function testApplyAbortsOnRateLimitAfterOneRetry(): void
    {
        $hospitals = $this->seedHospitals();
        $httpClient = new MockHttpClient([
            new MockResponse('{"error":"Quota exceeded"}', ['http_code' => 403]),
            new MockResponse('{"error":"Quota exceeded"}', ['http_code' => 403]),
        ]);
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('rate limit reached', $tester->getDisplay());
        self::assertNull($this->reload($hospitals['withoutCoords'])->getLatitude());
    }

    /**
     * @return array{stateId: int, withCoords: Hospital, withoutCoords: Hospital, missingAddress: Hospital, otherState: Hospital}
     */
    private function seedHospitals(): array
    {
        $owner = UserFactory::createOne(['username' => 'geo-owner']);
        $createdBy = UserFactory::createOne(['username' => 'geo-user']);
        $hessen = StateFactory::createOne(['name' => 'Hessen']);
        $hessenId = $hessen->getId();
        self::assertNotNull($hessenId);
        $bayern = StateFactory::createOne(['name' => 'Bayern']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $hessen]);
        $bayernDispatch = DispatchAreaFactory::createOne(['name' => 'München', 'state' => $bayern]);
        $address = [
            'street' => 'Mönchebergstraße 41-43',
            'postalCode' => '34125',
            'city' => 'Kassel',
            'country' => 'Deutschland',
        ];

        return [
            'stateId' => $hessenId,
            'withCoords' => HospitalFactory::createOne([
                'name' => 'Mit Koordinaten',
                'state' => $hessen,
                'dispatchArea' => $dispatch,
                'latitude' => 50.1109,
                'longitude' => 8.6821,
                'address' => AddressFactory::new($address)->create(),
                'createdBy' => $createdBy,
                'owner' => $owner,
            ]),
            'withoutCoords' => HospitalFactory::createOne([
                'name' => 'Ohne Koordinaten',
                'state' => $hessen,
                'dispatchArea' => $dispatch,
                'latitude' => null,
                'longitude' => null,
                'isParticipating' => false,
                'address' => AddressFactory::new($address)->create(),
                'createdBy' => $createdBy,
                'owner' => $owner,
            ]),
            'missingAddress' => HospitalFactory::createOne([
                'name' => 'Ohne Adresse',
                'state' => $hessen,
                'dispatchArea' => $dispatch,
                'latitude' => null,
                'longitude' => null,
                'address' => AddressFactory::new([
                    'street' => '',
                    'postalCode' => '',
                    'city' => '',
                    'country' => 'Deutschland',
                ]),
                'createdBy' => $createdBy,
                'owner' => $owner,
            ]),
            'otherState' => HospitalFactory::createOne([
                'name' => 'Bayern Klinik',
                'state' => $bayern,
                'dispatchArea' => $bayernDispatch,
                'latitude' => 48.1,
                'longitude' => 11.5,
                'createdBy' => $createdBy,
                'owner' => $owner,
            ]),
        ];
    }

    private function createTester(MockHttpClient $httpClient, string $apiKey): CommandTester
    {
        $command = new GeocodeHospitalCoordinatesCommand(
            new HospitalGeocodeService(
                new HospitalGeoScopeResolver(
                    self::getContainer()->get(HospitalLookupInterface::class),
                    self::getContainer()->get(DispatchAreaLookupInterface::class),
                    self::getContainer()->get(StateLookupInterface::class),
                ),
                new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), $apiKey),
                self::getContainer()->get(EntityManagerInterface::class),
            ),
        );

        return new CommandTester($command);
    }

    private function reload(Hospital $hospital): Hospital
    {
        $id = $hospital->getId();
        self::assertNotNull($id);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->find(Hospital::class, $id);
        self::assertInstanceOf(Hospital::class, $reloaded);

        return $reloaded;
    }

    private function failingHttpClient(): MockHttpClient
    {
        return new MockHttpClient(static function (): MockResponse {
            Assert::fail('OpenRouteService must not be called.');
        });
    }

    private function successResponse(): MockResponse
    {
        return new MockResponse(json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => ['type' => 'Point', 'coordinates' => [9.5081, 51.3224]],
                    'properties' => [
                        'layer' => 'address',
                        'label' => 'Mönchebergstraße 41-43, 34125 Kassel, Germany',
                        'country_a' => 'DEU',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
