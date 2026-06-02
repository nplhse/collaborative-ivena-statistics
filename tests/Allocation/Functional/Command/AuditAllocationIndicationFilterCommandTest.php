<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AuditAllocationIndicationFilterCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testJsonOptionReturnsProblematicRowsAsJson(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--code' => '999001',
            '--min-estimate' => '0',
            '--json' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();
        self::assertStringContainsString('"code": 999001', $display);
        self::assertStringContainsString('"actualNormalized": 0', $display);
    }

    public function testShowsSuccessWhenNoProblematicCodesFound(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--code' => '999002',
            '--min-estimate' => '999999',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No mismatches found among 1 code(s).', $tester->getDisplay());
    }

    public function testShowsWarningAndTableForProblematicDistinctCodeScan(): void
    {
        IndicationNormalizedFactory::createOne([
            'code' => 999003,
            'name' => 'Coverage test indication',
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--min-estimate' => '0',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Found 1 code(s) where estimate > 0 but list query returns no rows', $display);
        self::assertStringContainsString('actual normalized', $display);
        self::assertStringContainsString('999003', $display);
    }

    private function commandTester(): CommandTester
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('app:audit-allocation-indication-filter');

        return new CommandTester($command);
    }
}
