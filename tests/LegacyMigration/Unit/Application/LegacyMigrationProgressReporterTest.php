<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Unit\Application;

use App\LegacyMigration\Application\Service\LegacyMigrationProgressReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LegacyMigrationProgressReporterTest extends TestCase
{
    public function testProgressCanBeEnabledAndDisabled(): void
    {
        $this->expectNotToPerformAssertions();

        $reporter = new LegacyMigrationProgressReporter();
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        $reporter->startPhase($io, 'Users', 10, true);
        $reporter->advance();
        $reporter->finish();

        $reporter->startPhase($io, 'Users', 10, false);
        $reporter->advance();
        $reporter->finish();
    }
}
