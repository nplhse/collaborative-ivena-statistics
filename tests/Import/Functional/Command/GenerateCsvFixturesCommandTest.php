<?php

declare(strict_types=1);

namespace App\Tests\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Path;

final class GenerateCsvFixturesCommandTest extends KernelTestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fixturesDir = self::getContainer()->getParameter('kernel.project_dir').'/tests/Import/Fixtures/csv';
    }

    public function testCommandGeneratesFiles(): void
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:generate-csv-fixtures');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(0, $exitCode, 'Command should exit successfully');

        $expectedFiles = [
            'utf8.csv',
            'utf8_bom.csv',
            'iso8859_1.csv',
        ];

        foreach ($expectedFiles as $file) {
            $path = Path::join($this->fixturesDir, $file);
            self::assertFileExists($path, "Fixture $file should exist");
            self::assertGreaterThan(0, filesize($path), "Fixture $file should not be empty");
        }
    }
}
