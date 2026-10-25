<?php

declare(strict_types=1);

namespace App\Tests\Storage\Functional\Command;

use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Application\Event\StorageMeasured;
use App\Storage\Application\Layout\StorageLayout;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;

final class AnalyzeStorageCommandTest extends DatabaseKernelTestCase
{
    private StorageFixtureDirectory $fixture;

    protected function setUp(): void
    {
        $this->fixture = new StorageFixtureDirectory();
        $this->fixture->file('src/Kernel.php', 100);
        $this->fixture->file('vendor/pkg/lib.php', 7000);
        $this->fixture->file('var/imports/a.csv', 5000);
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
        parent::tearDown();
    }

    public function testPrintsTheBreakdownAsTable(): void
    {
        $tester = $this->tester($this->fixture->path);

        self::assertSame(Command::SUCCESS, $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Storage analysis', $display);
        self::assertStringContainsString('standalone', $display);
        self::assertStringContainsString('By category', $display);
        self::assertStringContainsString('By location', $display);
        self::assertStringContainsString('Contributors', $display);
        self::assertStringContainsString('var_imports', $display);
        self::assertStringContainsString('Dry run: nothing was stored.', $display);
    }

    public function testPrintsJson(): void
    {
        $tester = $this->tester($this->fixture->path);

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));

        $payload = json_decode($tester->getDisplay(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('standalone', $payload['mode']);
        self::assertSame($this->fixture->path, $payload['root']);
        self::assertIsInt($payload['used_bytes']);
        self::assertGreaterThan(12_000, $payload['used_bytes']);
        self::assertSame([], $payload['failures']);
        self::assertContains('var_imports', array_column($payload['items'], 'contributor'));
        self::assertSame(['database', 'imports', 'media', 'application', 'temporary', 'other'], array_column($payload['categories'], 'key'));
    }

    public function testFailsWhenTheFilesCannotBeMeasured(): void
    {
        $tester = $this->tester($this->fixture->path.'/missing');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('could not be measured', $tester->getDisplay());
        self::assertStringContainsString('unknown', $tester->getDisplay());
    }

    public function testStoresTheMeasurementWithPersist(): void
    {
        $announced = $this->countAnnouncements();
        $tester = $this->tester($this->fixture->path);

        self::assertSame(Command::SUCCESS, $tester->execute(['--persist' => true]));
        self::assertStringContainsString('Stored as the measurement of', $this->flat($tester));
        self::assertStringContainsString('Storage alerts and the daily report were triggered.', $this->flat($tester));
        self::assertSame(1, $this->rowCount());
        self::assertSame(1, $announced->count);

        self::assertSame(Command::SUCCESS, $tester->execute(['--persist' => true, '--no-notify' => true]));
        self::assertStringContainsString('replacing the earlier run of that day', $this->flat($tester));
        self::assertStringContainsString('were not triggered (--no-notify)', $this->flat($tester));
        self::assertSame(1, $this->rowCount());
        self::assertSame(1, $announced->count);
    }

    public function testReportsTheStoredDayInJson(): void
    {
        $tester = $this->tester($this->fixture->path);

        self::assertSame(Command::SUCCESS, $tester->execute(['--persist' => true, '--format' => 'json']));

        $payload = json_decode($tester->getDisplay(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['stored']);
        self::assertFalse($payload['stored']['replaced_earlier_run']);
        self::assertTrue($payload['stored']['announced']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $payload['stored']['measured_on']);
    }

    public function testADryRunStoresNothing(): void
    {
        $tester = $this->tester($this->fixture->path);

        self::assertSame(Command::SUCCESS, $tester->execute(['--format' => 'json']));
        self::assertNull(json_decode($tester->getDisplay(), true, 16, \JSON_THROW_ON_ERROR)['stored']);
        self::assertSame(0, $this->rowCount());
    }

    public function testNoNotifyNeedsPersist(): void
    {
        $tester = $this->tester($this->fixture->path);

        self::assertSame(Command::INVALID, $tester->execute(['--no-notify' => true]));
        self::assertStringContainsString('--no-notify only applies together with --persist', $tester->getDisplay());
    }

    public function testPersistFailsWhileAnotherMeasurementRuns(): void
    {
        $tester = $this->tester($this->fixture->path);
        $lock = self::getContainer()->get(LockFactory::class)->createLock('storage-measurement');
        self::assertTrue($lock->acquire());

        try {
            self::assertSame(Command::FAILURE, $tester->execute(['--persist' => true]));
        } finally {
            $lock->release();
        }

        self::assertStringContainsString('Another storage measurement is running', $tester->getDisplay());
        self::assertSame(0, $this->rowCount());
    }

    public function testRejectsAnUnknownFormat(): void
    {
        $tester = $this->tester($this->fixture->path);

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'xml']));
        self::assertStringContainsString('Unknown format "xml"', $tester->getDisplay());
    }

    /**
     * The display without the line breaks and "!" margins of wrapped note boxes.
     */
    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s*\n\s*!?\s*/', ' ', $tester->getDisplay());
    }

    private function rowCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM storage_measurement');
    }

    private function countAnnouncements(): \stdClass
    {
        self::bootKernel();
        $counter = new \stdClass();
        $counter->count = 0;
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(StorageMeasured::class, static function () use ($counter): void {
            ++$counter->count;
        });

        return $counter;
    }

    private function tester(string $root): CommandTester
    {
        if (!self::$kernel instanceof \Symfony\Component\HttpKernel\KernelInterface || !self::$booted) {
            self::bootKernel();
        }

        self::getContainer()->set(StorageLayoutDetectorInterface::class, new readonly class($root) implements StorageLayoutDetectorInterface {
            public function __construct(private string $root)
            {
            }

            public function detect(): StorageLayout
            {
                return StorageLayout::standalone($this->root);
            }
        });

        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:storage:analyze'));
    }
}
