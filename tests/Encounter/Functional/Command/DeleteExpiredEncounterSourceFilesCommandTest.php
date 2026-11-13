<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Manual run of the encounter upload file retention (#1049).
 */
#[ResetDatabase]
final class DeleteExpiredEncounterSourceFilesCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private Hospital $hospital;

    private User $uploader;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-10-01 10:00:00');
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testDryRunListsTheExpiredFileAndTheRealRunDeletesIt(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->build(), 'expired');
        self::mockTime('2026-11-01 10:00:00');

        $dryRun = $this->tester();
        self::assertSame(Command::SUCCESS, $dryRun->execute(['--dry-run' => true]));
        self::assertStringContainsString('would delete', $dryRun->getDisplay());
        self::assertTrue($this->sourceFileExists($importId));

        $run = $this->tester();
        self::assertSame(Command::SUCCESS, $run->execute([]));
        self::assertMatchesRegularExpression('/'.$importId.'\s+deleted/', $run->getDisplay());
        self::assertFalse($this->sourceFileExists($importId));

        $again = $this->tester();
        self::assertSame(Command::SUCCESS, $again->execute([]));
        self::assertStringContainsString('No encounter upload file is past the retention period.', $again->getDisplay());
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('app:encounter:delete-expired-source-files'));
    }
}
