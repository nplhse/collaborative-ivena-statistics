<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Unit\Application;

use App\LegacyMigration\Application\Service\LegacyMigrationConsoleProgress;
use App\LegacyMigration\Application\Service\LegacyMigrationProgressReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LegacyMigrationProgressReporterTest extends TestCase
{
    public function testProgressShowsPercentAndMessage(): void
    {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $progress = new LegacyMigrationConsoleProgress($io, new LegacyMigrationProgressReporter());

        $progress->startPhase('Allocations', 100);
        $progress->setMessage('Overall: import 1/3 | Legacy import #42: 10/50 (20%)');
        $progress->advance(25);
        $progress->finishPhase();

        $written = $output->fetch();
        self::assertStringContainsString('Allocations', $written);
        self::assertStringContainsString('%', $written);
        self::assertStringContainsString('Legacy import #42', $written);
    }

    public function testReporterCanBeDisabledByNotStarting(): void
    {
        $this->expectNotToPerformAssertions();

        $reporter = new LegacyMigrationProgressReporter();
        $reporter->advance();
        $reporter->setMessage('ignored');
        $reporter->finish();
    }
}
