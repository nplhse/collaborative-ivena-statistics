<?php

declare(strict_types=1);

namespace App\Tests\Storage\Functional\Command;

use App\Storage\Application\Contract\StorageLayoutDetectorInterface;
use App\Storage\Application\Layout\StorageLayout;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\Tests\Support\Storage\StorageFixtureDirectory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

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

    public function testRejectsAnUnknownFormat(): void
    {
        $tester = $this->tester($this->fixture->path);

        self::assertSame(Command::INVALID, $tester->execute(['--format' => 'xml']));
        self::assertStringContainsString('Unknown format "xml"', $tester->getDisplay());
    }

    private function tester(string $root): CommandTester
    {
        self::bootKernel();
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
