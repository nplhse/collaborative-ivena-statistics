<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Application\Contracts\HospitalLookupInterface;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocationApplier;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocationPreparer;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\AddressFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Geo\HospitalIsochroneFileStore;
use App\Allocation\Infrastructure\Geo\PreparedLocationFileStore;
use App\Allocation\Infrastructure\Http\OpenRouteServiceGeocodeClient;
use App\Allocation\Infrastructure\Http\OpenRouteServiceIsochroneClient;
use App\Allocation\UI\Console\Command\ApplyPreparedLocationCommand;
use App\Allocation\UI\Console\Command\ListPreparedLocationsCommand;
use App\Allocation\UI\Console\Command\PrepareLocationCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Prepare online (mocked OpenRouteService), then list and apply without network access.
 * Addresses are made up; real prepared locations are never part of tests.
 */
#[ResetDatabase]
final class PreparedLocationCommandsTest extends KernelTestCase
{
    use Factories;

    private string $baseDir;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->baseDir = sys_get_temp_dir().'/prepared-location-commands-'.bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->baseDir);
        parent::tearDown();
    }

    public function testPrepareFromAddressStoresLocationAfterTwoRequests(): void
    {
        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            return str_contains($url, '/geocode/') ? $this->geocodeResponse() : $this->isochroneResponse();
        });

        $tester = $this->prepareTester($httpClient);
        $status = $tester->execute([
            'slug' => 'musterstadt',
            '--label' => 'Klinikum Musterstadt',
            '--street' => 'Musterstraße 1',
            '--postal-code' => '12345',
            '--city' => 'Musterstadt',
            '--apply' => true,
            '--delay-ms' => 0,
        ]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertCount(2, $requests);
        self::assertStringContainsString('/geocode/search/structured', $requests[0]);
        self::assertStringContainsString('/v2/isochrones/driving-car', $requests[1]);
        $stored = $this->locationStore()->find('musterstadt');
        self::assertNotNull($stored);
        self::assertSame(50.5, $stored->latitude);
        self::assertSame(8.25, $stored->longitude);

        $list = new CommandTester(new ListPreparedLocationsCommand($this->locationStore()));
        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString('Klinikum Musterstadt', $list->getDisplay());
        self::assertStringContainsString('musterstadt', $list->getDisplay());
    }

    public function testPrepareDryRunCallsNothing(): void
    {
        $tester = $this->prepareTester($this->failingHttpClient());

        $status = $tester->execute(['slug' => 'pin', '--lat' => '50.5', '--lng' => '8.25']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Dry run finished', $tester->getDisplay());
        self::assertFalse($this->locationStore()->exists('pin'));
    }

    public function testPrepareReportsRateLimitAndStoresNothing(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"error":"Rate limit exceeded"}', ['http_code' => 429]),
            new MockResponse('{"error":"Rate limit exceeded"}', ['http_code' => 429]),
        ]);
        $tester = $this->prepareTester($httpClient);

        $status = $tester->execute(['slug' => 'pin', '--lat' => '50.5', '--lng' => '8.25', '--apply' => true, '--delay-ms' => 0]);

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(2, $httpClient->getRequestsCount());
        self::assertStringContainsString('rate limit reached', $tester->getDisplay());
        self::assertStringContainsString('Nothing was stored', $tester->getDisplay());
        self::assertFalse($this->locationStore()->exists('pin'));
    }

    public function testPrepareWarnsWhenApplyAndDryRunAreBothPassed(): void
    {
        $httpClient = new MockHttpClient(fn (): MockResponse => $this->isochroneResponse());
        $tester = $this->prepareTester($httpClient);

        $status = $tester->execute(['slug' => 'pin', '--lat' => '50.5', '--lng' => '8.25', '--apply' => true, '--dry-run' => true, '--delay-ms' => 0]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('--apply takes precedence', $tester->getDisplay());
        self::assertTrue($this->locationStore()->exists('pin'));
    }

    public function testListWithoutLocationsPointsToThePrepareCommand(): void
    {
        $list = new CommandTester(new ListPreparedLocationsCommand($this->locationStore()));

        self::assertSame(Command::SUCCESS, $list->execute([]));
        self::assertStringContainsString('No prepared locations yet', $list->getDisplay());
        self::assertStringContainsString('app:geo:prepare-location', $list->getDisplay());
    }

    public function testApplyWarnsWhenApplyAndDryRunAreBothPassed(): void
    {
        $this->prepareFromPin();
        $hospital = $this->hospital(latitude: null, longitude: null, street: '');
        $tester = $this->applyTester();

        $status = $tester->execute(['slug' => 'musterstadt', '--hospital-id' => $hospital->getId(), '--apply' => true, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('--apply takes precedence', $tester->getDisplay());
        self::assertSame(50.5, $this->reloadHospital($hospital)->getLatitude());
    }

    public function testPrepareRejectsNonNumericCoordinates(): void
    {
        $tester = $this->prepareTester($this->failingHttpClient());

        self::assertSame(Command::FAILURE, $tester->execute(['slug' => 'pin', '--lat' => 'north', '--lng' => '8.25']));
        self::assertStringContainsString('--lat must be a decimal number', $tester->getDisplay());
    }

    public function testApplySetsCoordinatesFillsEmptyAddressAndWritesIsochroneFile(): void
    {
        $this->prepareFromPin();
        $hospital = $this->hospital(latitude: null, longitude: null, street: '');

        $tester = $this->applyTester();
        $status = $tester->execute(['slug' => 'musterstadt', '--hospital-id' => $hospital->getId(), '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $reloaded = $this->reloadHospital($hospital);
        self::assertSame(50.5, $reloaded->getLatitude());
        self::assertSame(8.25, $reloaded->getLongitude());
        self::assertSame('Musterstraße 1', $reloaded->getAddress()->getStreet());
        self::assertSame('12345', $reloaded->getAddress()->getPostalCode());
        $isochrones = $this->isochroneStore()->findForHospital($reloaded);
        self::assertIsArray($isochrones);
        self::assertSame(['lat' => 50.5, 'lng' => 8.25], $isochrones['properties']['origin'] ?? null);
        self::assertCount(2, $isochrones['features']);
    }

    public function testApplyKeepsExistingAddress(): void
    {
        $this->prepareFromPin();
        $hospital = $this->hospital(latitude: null, longitude: null, street: 'Eigene Straße 9');

        $status = $this->applyTester()->execute(['slug' => 'musterstadt', '--hospital-id' => $hospital->getId(), '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame('Eigene Straße 9', $this->reloadHospital($hospital)->getAddress()->getStreet());
    }

    public function testApplyDryRunChangesNothing(): void
    {
        $this->prepareFromPin();
        $hospital = $this->hospital(latitude: null, longitude: null, street: '');

        $tester = $this->applyTester();
        $status = $tester->execute(['slug' => 'musterstadt', '--hospital-id' => $hospital->getId()]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Dry run finished', $tester->getDisplay());
        self::assertNull($this->reloadHospital($hospital)->getLatitude());
        self::assertFalse($this->isochroneStore()->existsForHospital($hospital));
    }

    public function testApplyRefusesDifferentCoordinatesWithoutForce(): void
    {
        $this->prepareFromPin();
        $hospital = $this->hospital(latitude: 51.0, longitude: 9.0, street: 'Eigene Straße 9');
        $tester = $this->applyTester();

        $refused = $tester->execute(['slug' => 'musterstadt', '--hospital-id' => $hospital->getId(), '--apply' => true]);

        self::assertSame(Command::FAILURE, $refused);
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame(51.0, $this->reloadHospital($hospital)->getLatitude());

        $forced = $tester->execute(['slug' => 'musterstadt', '--hospital-id' => $hospital->getId(), '--apply' => true, '--force' => true]);

        self::assertSame(Command::SUCCESS, $forced);
        self::assertSame(50.5, $this->reloadHospital($hospital)->getLatitude());
    }

    public function testApplyTwiceReportsNothingToDo(): void
    {
        $this->prepareFromPin();
        $hospital = $this->hospital(latitude: null, longitude: null, street: '');
        $tester = $this->applyTester();
        $tester->execute(['slug' => 'musterstadt', '--hospital-id' => $hospital->getId(), '--apply' => true]);

        $status = $tester->execute(['slug' => 'musterstadt', '--hospital-id' => $hospital->getId(), '--apply' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Nothing to do', $tester->getDisplay());
    }

    public function testApplyUnknownLocationOrHospitalFails(): void
    {
        $hospital = $this->hospital(latitude: null, longitude: null, street: '');
        $tester = $this->applyTester();

        self::assertSame(Command::FAILURE, $tester->execute(['slug' => 'unbekannt', '--hospital-id' => $hospital->getId()]));
        self::assertStringContainsString('Unknown or unreadable prepared location', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['slug' => 'unbekannt', '--hospital-id' => 999999]));
        self::assertStringContainsString('Unknown hospital', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['slug' => 'unbekannt']));
        self::assertStringContainsString('--hospital-id', $tester->getDisplay());
    }

    private function prepareFromPin(): void
    {
        $httpClient = new MockHttpClient(fn (): MockResponse => $this->isochroneResponse());
        $status = $this->prepareTester($httpClient)->execute([
            'slug' => 'musterstadt',
            '--street' => 'Musterstraße 1',
            '--postal-code' => '12345',
            '--city' => 'Musterstadt',
            '--lat' => '50.5',
            '--lng' => '8.25',
            '--apply' => true,
            '--delay-ms' => 0,
        ]);
        self::assertSame(Command::SUCCESS, $status);
    }

    private function hospital(?float $latitude, ?float $longitude, string $street): Hospital
    {
        $state = StateFactory::createOne(['name' => 'Hessen']);

        return HospitalFactory::createOne([
            'name' => 'Synthetische Klinik',
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['name' => 'Musterkreis', 'state' => $state]),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'address' => AddressFactory::new([
                'street' => $street,
                'postalCode' => '' === $street ? '' : '99999',
                'city' => '' === $street ? '' : 'Anderswo',
                'state' => 'Hessen',
                'country' => 'DE',
            ]),
            'createdBy' => UserFactory::createOne(),
            'owner' => UserFactory::createOne(),
        ]);
    }

    private function reloadHospital(Hospital $hospital): Hospital
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Hospital::class, $hospital->getId());
        self::assertInstanceOf(Hospital::class, $reloaded);

        return $reloaded;
    }

    private function prepareTester(MockHttpClient $httpClient): CommandTester
    {
        return new CommandTester(new PrepareLocationCommand(new PreparedLocationPreparer(
            self::getContainer()->get(HospitalLookupInterface::class),
            new OpenRouteServiceGeocodeClient($httpClient, new NullLogger(), 'test-key'),
            new OpenRouteServiceIsochroneClient($httpClient, new NullLogger(), 'test-key'),
            $this->isochroneStore(),
            $this->locationStore(),
            new MockClock('2026-10-28 12:00:00'),
        )));
    }

    private function applyTester(): CommandTester
    {
        return new CommandTester(new ApplyPreparedLocationCommand(
            new PreparedLocationApplier(
                $this->locationStore(),
                $this->isochroneStore(),
                self::getContainer()->get(EntityManagerInterface::class),
            ),
            self::getContainer()->get(HospitalLookupInterface::class),
        ));
    }

    private function locationStore(): PreparedLocationFileStore
    {
        return new PreparedLocationFileStore($this->baseDir.'/prepared-locations');
    }

    private function isochroneStore(): HospitalIsochroneFileStore
    {
        return new HospitalIsochroneFileStore($this->baseDir.'/hospital-isochrones');
    }

    private function failingHttpClient(): MockHttpClient
    {
        return new MockHttpClient(static function (): MockResponse {
            Assert::fail('OpenRouteService must not be called.');
        });
    }

    private function geocodeResponse(): MockResponse
    {
        return new MockResponse(json_encode([
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [8.25, 50.5]],
                'properties' => ['layer' => 'address', 'label' => 'Musterstraße 1, 12345 Musterstadt, Germany', 'country_a' => 'DEU'],
            ]],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function isochroneResponse(): MockResponse
    {
        return new MockResponse(json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                ['type' => 'Feature', 'properties' => ['value' => 300], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[8.2, 50.4], [8.3, 50.4], [8.3, 50.6], [8.2, 50.4]]]]],
                ['type' => 'Feature', 'properties' => ['value' => 600], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[8.1, 50.3], [8.4, 50.3], [8.4, 50.7], [8.1, 50.3]]]]],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
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

            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
