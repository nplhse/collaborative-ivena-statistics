<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Demo;

use App\Allocation\Application\Contracts\HospitalIsochroneStoreInterface;
use App\Allocation\Application\Contracts\PreparedLocationStoreInterface;
use App\Allocation\Application\Hospital\IsochroneOrigin;
use App\Allocation\Application\Hospital\PreparedLocation\PreparedLocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Geo\HospitalIsochroneFileStore;
use App\Allocation\Infrastructure\Http\OpenRouteServiceGeocodeClient;
use App\Allocation\Infrastructure\Http\OpenRouteServiceIsochroneClient;
use App\DataFixtures\Generator\Application\Demo\DemoHospitalSetup;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The demo wizard `app:demo:hospital:create` (#791 step 4) on the test preset `minimal`. Today is 2026-10-28, so a
 * period of 6 months runs from 2026-03 to 2026-08 and leaves 2026-09 for the CSV upload. Background messages are
 * handled at once in tests, so the run is projected and the owner notified when the command returns.
 */
#[ResetDatabase]
final class CreateDemoHospitalCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const string SLUG = 'wizard-test-location';

    protected function setUp(): void
    {
        $_ENV['FIXTURES_UNTIL'] = $_SERVER['FIXTURES_UNTIL'] = '2026-09-30';
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-10-28 10:00:00'));
        $this->load();
    }

    protected function tearDown(): void
    {
        unset($_ENV['FIXTURES_UNTIL'], $_SERVER['FIXTURES_UNTIL']);
        $preparedFile = self::getContainer()->getParameter('app.prepared_locations_dir').'/'.self::SLUG.'.json';
        if (is_file($preparedFile)) {
            unlink($preparedFile);
        }
        parent::tearDown();
    }

    public function testTheInteractiveWizardCreatesAParticipantAHospitalAndItsDataset(): void
    {
        $users = $this->countRows('"user"');
        $tester = $this->tester();
        $tester->setInputs([
            'A new synthetic hospital',
            'Klinikum Demo Stadt',
            'Full',
            '500',
            '',                          // first dispatch area
            'No prepared location (fetched online when an OpenRouteService key is set)',
            '3650',                      // 10 a day
            '6',
            '5',
            'yes',
        ]);

        self::assertSame(Command::SUCCESS, $tester->execute([], ['interactive' => true]), $tester->getDisplay());
        $display = $tester->getDisplay();

        $hospital = $this->connection()->fetchAssociative("SELECT id, tier, size, beds, owner_id, is_participating, participating_since, latitude FROM hospital WHERE name = 'Klinikum Demo Stadt'");
        self::assertIsArray($hospital);
        self::assertSame(['Full', 'Medium', 500, true, '2026-03-01'], [$hospital['tier'], $hospital['size'], $hospital['beds'], $hospital['is_participating'], substr((string) $hospital['participating_since'], 0, 10)], '500 beds lie in the medium band (300 to 599)');
        self::assertNull($hospital['latitude']);

        self::assertSame($users + 1, $this->countRows('"user"'), 'one participant account');
        $user = $this->connection()->fetchAssociative('SELECT username, email, roles, is_enabled, is_verified FROM "user" WHERE id = :id', ['id' => $hospital['owner_id']]);
        self::assertIsArray($user);
        self::assertSame(['demo', 'demo@demo.invalid'], [$user['username'], $user['email']], 'the first demo participant is called demo');
        self::assertTrue($user['is_enabled'] && $user['is_verified']);
        self::assertEqualsCanonicalizing(['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA'], json_decode((string) $user['roles'], true));
        self::assertMatchesRegularExpression('/Participant password\s+Ivena!1234/', $display, 'the known demo password');
        self::assertStringContainsString('a new account "demo", password Ivena!1234', $display, 'the summary names the account');
        $setup = self::getContainer()->get(DemoHospitalSetup::class);
        self::assertSame('demo2', $setup->nextUsername(), 'the next participant is numbered');
        $this->connection()->executeStatement("UPDATE \"user\" SET username = 'demo7' WHERE username = 'demo'");
        self::assertSame('demo8', $setup->nextUsername(), 'numbering continues after the highest number');

        $hospitalId = (int) $hospital['id'];
        self::assertSame(
            ['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08'],
            $this->connection()->fetchFirstColumn("SELECT to_char(MIN(a.created_at), 'YYYY-MM') FROM allocation a JOIN import i ON i.id = a.import_id WHERE a.hospital_id = :id AND i.file_path LIKE 'synthetic/allocations/%' GROUP BY i.id ORDER BY 1", ['id' => $hospitalId]),
            'six monthly allocation imports up to the month before last',
        );
        self::assertSame(6, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM import WHERE hospital_id = :id AND file_path LIKE 'synthetic/closures/%'", ['id' => $hospitalId]), 'six monthly closure imports');
        // 3,650 a year are 10 a day; March to August has 184 days.
        self::assertEqualsWithDelta(1840, $this->rows('allocation', $hospitalId), 450);
        self::assertGreaterThan(0, $this->rows('closure_interval', $hospitalId), '5 % closed department-hours');
        self::assertSame($this->rows('allocation', $hospitalId), $this->rows('allocation_stats_projection', $hospitalId), 'the background stage projected every allocation');

        $run = $this->connection()->fetchAssociative('SELECT id, trigger, notify, status FROM generation_run');
        self::assertIsArray($run);
        self::assertSame(['wizard', true, 'completed'], [$run['trigger'], $run['notify'], $run['status']]);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'generation.completed' AND recipient_id = :user", ['user' => $hospital['owner_id']]), 'the participant is notified');

        foreach (['Participant', 'Hospital', 'Monthly imports', 'Allocations', 'Closures', 'Projections'] as $stage) {
            self::assertMatchesRegularExpression('/✔ '.$stage.'\s+completed/u', $display, $stage);
        }
        self::assertMatchesRegularExpression('/– Location\s+skipped\s+no map pin: no OpenRouteService key/u', $display, 'missing geodata is information, not a failure');
        self::assertStringContainsString('6/6 months', $display);
        self::assertStringContainsString('app:generate:run:show '.$run['id'], $display);
        self::assertStringContainsString('2026-09 stays free for the CSV upload', $display);
        self::assertStringContainsString('500 beds → Medium', $display);
        self::assertStringContainsString('Small 80–299, Medium 300–599, Large 600–1300', $display, 'the summary names the bed bands');
    }

    public function testAnExistingHospitalKeepsItsMasterDataAndTheDemoParticipantGetsAccess(): void
    {
        $id = (int) $this->connection()->fetchOne('SELECT h.id FROM hospital h WHERE h.is_participating AND h.owner_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM import i WHERE i.hospital_id = h.id) ORDER BY h.id LIMIT 1');
        self::assertGreaterThan(0, $id);
        $masterData = $this->masterData($id);

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--hospital' => $masterData['public_id'],
            '--annual-volume' => 3650,
            '--months' => 6,
            '--closure-share' => 0.02,
            '--yes' => true,
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame($masterData, $this->masterData($id), 'name, tier, size, beds, location, address, owner and dispatch area stay');
        $grant = $this->connection()->fetchAssociative('SELECT g.permissions, u.username FROM hospital_access_grant g JOIN "user" u ON u.id = g.user_id WHERE g.hospital_id = :id', ['id' => $id]);
        self::assertIsArray($grant);
        self::assertSame(31, $grant['permissions'], 'view, statistics, import, export and benchmarking');
        self::assertSame('demo', $grant['username']);
        self::assertStringContainsString('This is the test environment, not the demo', $tester->getDisplay(), 'a run outside the demo environment is pointed out');
        self::assertSame(6, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM import WHERE hospital_id = :id AND file_path LIKE 'synthetic/allocations/%'", ['id' => $id]));
        self::assertStringContainsString('owner '.$masterData['owner'].' stays; both are notified', $tester->getDisplay());
        self::assertSame(
            [$masterData['owner'], $grant['username']],
            $this->connection()->fetchFirstColumn("SELECT u.username FROM notification n JOIN \"user\" u ON u.id = n.recipient_id WHERE n.type = 'generation.completed' AND n.scope_reference_id = :id ORDER BY u.id", ['id' => $id]),
            'the owner and the demo participant are each notified once',
        );
    }

    public function testARefusedDatasetLeavesNoAccountAndNoHospital(): void
    {
        // A hospital of the minimal preset with generated data in August 2026.
        $id = (int) $this->connection()->fetchOne("SELECT hospital_id FROM import WHERE file_path LIKE 'synthetic/allocations/%2026-08.csv' ORDER BY file_path LIMIT 1");
        $publicId = (string) $this->connection()->fetchOne('SELECT public_id FROM hospital WHERE id = :id', ['id' => $id]);
        $before = [$this->countRows('"user"'), $this->countRows('hospital'), $this->countRows('hospital_access_grant'), $this->countRows('import')];

        $tester = $this->tester();
        $exitCode = $tester->execute(['--hospital' => $publicId, '--annual-volume' => 3650, '--months' => 6, '--closure-share' => 0.05, '--yes' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('already has generated data in 2026-08', $tester->getDisplay());
        self::assertStringContainsString('--existing=replace', $tester->getDisplay());
        self::assertSame($before, [$this->countRows('"user"'), $this->countRows('hospital'), $this->countRows('hospital_access_grant'), $this->countRows('import')], 'the transaction was rolled back');
        self::assertSame(0, $this->countRows('generation_run'));
    }

    public function testAPreparedLocationGivesTheNewHospitalCoordinatesAndIsochronesOffline(): void
    {
        /** @var PreparedLocationStoreInterface $locations */
        $locations = self::getContainer()->get(PreparedLocationStoreInterface::class);
        $locations->save(new PreparedLocation(
            slug: self::SLUG,
            label: 'Wizard test',
            address: null,
            latitude: 50.5,
            longitude: 8.25,
            isochrones: IsochroneOrigin::withCoordinates(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['value' => 600], 'geometry' => ['type' => 'Polygon', 'coordinates' => []]]]], 50.5, 8.25),
            preparedAt: new \DateTimeImmutable('2026-10-01'),
        ));

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--name' => 'Kreisklinik Vorbereitet',
            '--tier' => 'basic',
            '--beds' => 180,
            '--location' => self::SLUG,
            '--annual-volume' => 3650,
            '--months' => 6,
            '--closure-share' => 0,
            '--yes' => true,
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $hospital = $this->entityManager()->getRepository(Hospital::class)->findOneBy(['name' => 'Kreisklinik Vorbereitet']);
        self::assertInstanceOf(Hospital::class, $hospital);
        self::assertSame([50.5, 8.25, 'Small'], [$hospital->getLatitude(), $hospital->getLongitude(), $hospital->getSize()?->value]);
        /** @var HospitalIsochroneStoreInterface $isochrones */
        $isochrones = self::getContainer()->get(HospitalIsochroneStoreInterface::class);
        self::assertTrue($isochrones->existsForHospital($hospital));
        self::assertSame(0, $this->rows('closure_interval', (int) $hospital->getId()), 'a closure share of 0 closes nothing');

        if ($isochrones instanceof HospitalIsochroneFileStore && null !== ($path = $isochrones->pathFor($hospital)) && is_file($path)) {
            unlink($path);
        }
    }

    public function testMissingIsochronesAreFetchedOnlineWhenAKeyIsSet(): void
    {
        $requests = 0;
        $client = new OpenRouteServiceIsochroneClient(new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse(json_encode(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['value' => 600], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[8.2, 50.4], [8.3, 50.4], [8.3, 50.6], [8.2, 50.4]]]]]]], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }), new NullLogger(), 'test-key');
        self::getContainer()->set(OpenRouteServiceIsochroneClient::class, $client);
        self::getContainer()->set(OpenRouteServiceGeocodeClient::class, new OpenRouteServiceGeocodeClient(new MockHttpClient(static fn (): never => throw new \LogicException('The coordinates are given; nothing is geocoded.')), new NullLogger(), 'test-key'));

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--name' => 'Kreisklinik Online',
            '--tier' => 'basic',
            '--beds' => 180,
            '--latitude' => 50.5,
            '--longitude' => 8.25,
            '--annual-volume' => 3650,
            '--months' => 6,
            '--closure-share' => 0,
            '--yes' => true,
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(1, $requests);
        self::assertMatchesRegularExpression('/✔ Location\s+completed\s+coordinates and isochrones fetched online/u', $tester->getDisplay());
        $hospital = $this->entityManager()->getRepository(Hospital::class)->findOneBy(['name' => 'Kreisklinik Online']);
        self::assertInstanceOf(Hospital::class, $hospital);
        /** @var HospitalIsochroneStoreInterface $isochrones */
        $isochrones = self::getContainer()->get(HospitalIsochroneStoreInterface::class);
        self::assertTrue($isochrones->existsForHospital($hospital));
        if ($isochrones instanceof HospitalIsochroneFileStore && null !== ($path = $isochrones->pathFor($hospital)) && is_file($path)) {
            unlink($path);
        }
    }

    public function testWrongAnswersAreRefusedBeforeAnythingIsWritten(): void
    {
        $users = $this->countRows('"user"');
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--annual-volume' => 1000], ['interactive' => false]), 'a new hospital needs a name');
        self::assertSame(Command::INVALID, $tester->execute(['--name' => 'X', '--tier' => 'maximal', '--beds' => 100], ['interactive' => false]));
        self::assertSame(Command::INVALID, $tester->execute(['--name' => 'X', '--tier' => 'basic', '--beds' => 100, '--annual-volume' => 1000, '--months' => 9, '--closure-share' => 0.05], ['interactive' => false]));
        self::assertStringContainsString('6, 12, 24 months', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--name' => 'X', '--tier' => 'basic', '--beds' => 100, '--annual-volume' => 1000, '--months' => 6, '--closure-share' => 0.05, '--location' => 'nowhere', '--yes' => true], ['interactive' => false]));
        self::assertStringContainsString('Unknown prepared location "nowhere"', $tester->getDisplay());

        self::assertSame($users, $this->countRows('"user"'));
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM hospital WHERE name = 'X'"));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new Application(self::$kernel)->find('app:demo:hospital:create'));
    }

    /**
     * @return array<string, mixed>
     */
    private function masterData(int $id): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT h.public_id, h.name, h.tier, h.size, h.beds, h.location, h.latitude, h.longitude, h.address_street, h.address_city, h.dispatch_area_id, h.state_id, u.username AS owner
             FROM hospital h JOIN "user" u ON u.id = h.owner_id WHERE h.id = :id',
            ['id' => $id],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function rows(string $table, int $hospital): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE hospital_id = :id', $table), ['id' => $hospital]);
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function load(): void
    {
        $container = self::getContainer();
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');
        $entityManager = $this->entityManager();

        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['allocations']), false);
        $entityManager->clear();
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
