<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Application\Contracts\DispatchAreaLookupInterface;
use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Contracts\StateLookupInterface;
use App\Allocation\Application\Hospital\HospitalGeoScopeResolver;
use App\Allocation\Application\Hospital\HospitalIsochroneFetchService;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Geo\HospitalIsochroneFileStore;
use App\Allocation\Infrastructure\Http\OpenRouteServiceIsochroneClient;
use App\Allocation\UI\Console\Command\FetchHospitalIsochronesCommand;
use App\User\Domain\Factory\UserFactory;
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
final class FetchHospitalIsochronesCommandTest extends KernelTestCase
{
    use Factories;

    private string $isoDir;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->isoDir = sys_get_temp_dir().'/hospital-isochrones-'.bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->isoDir);
        parent::tearDown();
    }

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

    public function testUnknownStateExitsWithFailure(): void
    {
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--state-id' => 99999]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Unknown federal state', $tester->getDisplay());
    }

    public function testUnknownHospitalExitsWithFailure(): void
    {
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--hospital-id' => 99999]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Unknown hospital', $tester->getDisplay());
    }

    public function testUnknownDispatchAreaExitsWithFailure(): void
    {
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--dispatch-area-id' => 99999]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Unknown dispatch area', $tester->getDisplay());
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

    public function testDryRunDoesNotWriteFilesOrCallOpenRouteService(): void
    {
        $hospitals = $this->seedHospitals();
        $tester = $this->createTester($this->failingHttpClient(), 'test-key');

        $status = $tester->execute(['--state-id' => $hospitals['stateId']]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Teilnehmende Klinik', $display);
        self::assertStringContainsString('Nicht teilnehmend', $display);
        self::assertStringContainsString('Ohne Koordinaten', $display);
        self::assertStringNotContainsString('Bayern Klinik', $display);
        self::assertStringContainsString('missing-coords', $display);
        self::assertStringContainsString('fetch', $display);
        self::assertStringContainsString('OpenRouteService requests required', $display);
        self::assertFalse($this->store()->existsForHospital($hospitals['participating']));
        self::assertFalse($this->store()->existsForHospital($hospitals['nonParticipating']));
    }

    public function testApplyWritesFilesForHospitalsWithCoordinatesIncludingNonParticipating(): void
    {
        $hospitals = $this->seedHospitals();
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode($this->geojson(), JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(3, $requests);
        self::assertTrue($this->store()->existsForHospital($hospitals['participating']));
        self::assertTrue($this->store()->existsForHospital($hospitals['nonParticipating']));
        self::assertTrue($this->store()->existsForHospital($hospitals['otherArea']));
        self::assertFalse($this->store()->existsForHospital($hospitals['missingCoords']));
        self::assertFalse($this->store()->existsForHospital($hospitals['otherState']));
        $stored = $this->store()->findForHospital($hospitals['participating']);
        self::assertIsArray($stored);
        self::assertCount(2, $stored['features']);
        self::assertSame(600, $stored['features'][1]['properties']['value']);
        self::assertSame(['lat' => 50.1109, 'lng' => 8.6821], $stored['properties']['origin'] ?? null);
    }

    public function testParticipatingOnlySkipsNonParticipatingHospitals(): void
    {
        $hospitals = $this->seedHospitals();
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode($this->geojson(), JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--participating-only' => true,
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $requests);
        self::assertTrue($this->store()->existsForHospital($hospitals['participating']));
        self::assertFalse($this->store()->existsForHospital($hospitals['nonParticipating']));
        self::assertStringNotContainsString('Nicht teilnehmend', $tester->getDisplay());
    }

    public function testHospitalScopeProcessesNonParticipatingHospitalAndIgnoresParticipatingOnly(): void
    {
        $hospitals = $this->seedHospitals();
        $hospitalId = $hospitals['nonParticipating']->getId();
        self::assertNotNull($hospitalId);
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode($this->geojson(), JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--hospital-id' => $hospitalId,
            '--participating-only' => true,
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $requests);
        self::assertTrue($this->store()->existsForHospital($hospitals['nonParticipating']));
        self::assertFalse($this->store()->existsForHospital($hospitals['participating']));
        self::assertStringContainsString('--participating-only is ignored when --hospital-id is set.', $tester->getDisplay());
    }

    public function testDispatchAreaScopeDoesNotIncludeOtherAreas(): void
    {
        $hospitals = $this->seedHospitals();
        $dispatchAreaId = $hospitals['dispatchArea']->getId();
        self::assertNotNull($dispatchAreaId);
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode($this->geojson(), JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--dispatch-area-id' => $dispatchAreaId,
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(2, $requests);
        self::assertTrue($this->store()->existsForHospital($hospitals['participating']));
        self::assertFalse($this->store()->existsForHospital($hospitals['otherArea']));
        self::assertFalse($this->store()->existsForHospital($hospitals['otherState']));
    }

    public function testApplySkipsExistingFilesWhenOriginMatchesUnlessForced(): void
    {
        $hospitals = $this->seedHospitals();
        $this->store()->writeForHospital(
            $hospitals['participating'],
            [
                ...$this->geojson(),
                'properties' => ['origin' => ['lat' => 50.1109, 'lng' => 8.6821]],
            ],
        );
        $requests = (object) ['count' => 0];
        $httpClient = new MockHttpClient(function () use ($requests): MockResponse {
            ++$requests->count;

            return new MockResponse(json_encode($this->geojson(), JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $skip = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $skip);
        self::assertSame(2, $requests->count);
        self::assertStringContainsString('skip', $tester->getDisplay());

        $force = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--force' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $force);
        self::assertSame(5, $requests->count);
    }

    public function testApplyRefetchesWhenStoredOriginIsMissing(): void
    {
        $hospitals = $this->seedHospitals();
        $this->store()->writeForHospital($hospitals['participating'], $this->geojson());
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode($this->geojson(), JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(3, $requests);
        self::assertStringContainsString('fetch', $tester->getDisplay());
    }

    public function testApplyWarnsWhenRequestsFail(): void
    {
        $hospitals = $this->seedHospitals();
        $httpClient = new MockHttpClient([
            new MockResponse('{"error":"denied"}', ['http_code' => 401]),
            new MockResponse('{"error":"denied"}', ['http_code' => 401]),
            new MockResponse('{"error":"denied"}', ['http_code' => 401]),
        ]);
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('request(s) failed', $tester->getDisplay());
        self::assertFalse($this->store()->existsForHospital($hospitals['participating']));
    }

    public function testApplyAbortsOnRateLimitAfterOneRetryAndKeepsWrittenFiles(): void
    {
        $hospitals = $this->seedHospitals();
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($this->geojson(), JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse('{"error":"Rate limit exceeded"}', [
                'http_code' => 429,
                'response_headers' => ['retry-after' => '1'],
            ]),
            new MockResponse('{"error":"Rate limit exceeded"}', ['http_code' => 429]),
        ]);
        $tester = $this->createTester($httpClient, 'test-key');

        $status = $tester->execute([
            '--state-id' => $hospitals['stateId'],
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('rate limit reached', $tester->getDisplay());
        self::assertTrue($this->store()->existsForHospital($hospitals['otherArea']));
        self::assertFalse($this->store()->existsForHospital($hospitals['nonParticipating']));
        self::assertFalse($this->store()->existsForHospital($hospitals['participating']));
    }

    /**
     * @return array{
     *     stateId: int,
     *     dispatchArea: DispatchArea,
     *     participating: Hospital,
     *     nonParticipating: Hospital,
     *     missingCoords: Hospital,
     *     otherArea: Hospital,
     *     otherState: Hospital
     * }
     */
    private function seedHospitals(): array
    {
        $owner = UserFactory::createOne(['username' => 'iso-owner']);
        $createdBy = UserFactory::createOne(['username' => 'iso-user']);
        $hessen = StateFactory::createOne(['name' => 'Hessen']);
        $hessenId = $hessen->getId();
        self::assertNotNull($hessenId);
        $bayern = StateFactory::createOne(['name' => 'Bayern']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $hessen]);
        $otherDispatch = DispatchAreaFactory::createOne(['name' => 'Kassel', 'state' => $hessen]);
        $bayernDispatch = DispatchAreaFactory::createOne(['name' => 'München', 'state' => $bayern]);

        return [
            'stateId' => $hessenId,
            'dispatchArea' => $dispatch,
            'participating' => HospitalFactory::createOne([
                'name' => 'Teilnehmende Klinik',
                'state' => $hessen,
                'dispatchArea' => $dispatch,
                'latitude' => 50.1109,
                'longitude' => 8.6821,
                'isParticipating' => true,
                'createdBy' => $createdBy,
                'owner' => $owner,
            ]),
            'nonParticipating' => HospitalFactory::createOne([
                'name' => 'Nicht teilnehmend',
                'state' => $hessen,
                'dispatchArea' => $dispatch,
                'latitude' => 50.2,
                'longitude' => 8.7,
                'isParticipating' => false,
                'createdBy' => $createdBy,
                'owner' => $owner,
            ]),
            'missingCoords' => HospitalFactory::createOne([
                'name' => 'Ohne Koordinaten',
                'state' => $hessen,
                'dispatchArea' => $dispatch,
                'latitude' => null,
                'longitude' => null,
                'createdBy' => $createdBy,
                'owner' => $owner,
            ]),
            'otherArea' => HospitalFactory::createOne([
                'name' => 'Andere Leitstelle',
                'state' => $hessen,
                'dispatchArea' => $otherDispatch,
                'latitude' => 51.3,
                'longitude' => 9.5,
                'isParticipating' => false,
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
        $command = new FetchHospitalIsochronesCommand(
            new HospitalIsochroneFetchService(
                new HospitalGeoScopeResolver(
                    self::getContainer()->get(HospitalLookupInterface::class),
                    self::getContainer()->get(DispatchAreaLookupInterface::class),
                    self::getContainer()->get(StateLookupInterface::class),
                ),
                new OpenRouteServiceIsochroneClient($httpClient, new NullLogger(), $apiKey),
                $this->store(),
            ),
        );

        return new CommandTester($command);
    }

    private function store(): HospitalIsochroneFileStore
    {
        return new HospitalIsochroneFileStore($this->isoDir);
    }

    private function failingHttpClient(): MockHttpClient
    {
        return new MockHttpClient(static function (): MockResponse {
            Assert::fail('OpenRouteService must not be called.');
        });
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
                    'properties' => ['value' => 300],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[8.6, 50.1], [8.7, 50.1], [8.7, 50.2], [8.6, 50.2], [8.6, 50.1]]],
                    ],
                ],
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[8.5, 50.0], [8.8, 50.0], [8.8, 50.3], [8.5, 50.3], [8.5, 50.0]]],
                    ],
                ],
            ],
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
