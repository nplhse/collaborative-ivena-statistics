<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Command;

use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:encounter:learn-plausibility` (ADR 033, #1155): learns a draft from the eligible pairs and prints aggregates for
 * operators; refuses invalid days and does nothing without eligible pairs.
 */
#[ResetDatabase]
final class LearnPlausibilityCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-14 09:00:00');
    }

    public function testLearnsADraftAndPrintsTheReplay(): void
    {
        // A pass-1 link (9 points, no bonus involved): one eligible pair; below the minimum of 10 every cell keeps the seed.
        $hospital = $this->encounterHospital();
        $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '10:00', pzc: 711);
        $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), '10:05', diagram: 'urologisches_problem');
        $this->matchDay($hospital);

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => '2026-10-01', '--to' => '2026-10-31']));

        $draft = self::getContainer()->get(PlausibilityRepositoryInterface::class)->snapshots()[0];
        self::assertSame([PlausibilitySnapshotOrigin::Learned, 1], [$draft->getOrigin(), $draft->getEligiblePairs()]);
        $output = $tester->getDisplay();
        self::assertStringContainsString(\sprintf('Learned draft snapshot %d', (int) $draft->getId()), $output);
        self::assertStringContainsString('2026-10-12 – 2026-10-12', $output);
        self::assertStringContainsString('All hospitals (1 eligible pairs, 0 confirmed after a bonus decision)', $output);
        self::assertMatchesRegularExpression('/cross_fitted\s+1\s+1 \(1\.000\)/', $output);
    }

    public function testRefusesInvalidDaysAndLearnsNothingWithoutPairs(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::INVALID, $tester->execute(['--from' => '12.10.2026']));
        self::assertSame(Command::INVALID, $tester->execute(['--from' => '2026-10-31', '--to' => '2026-10-01']));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No eligible pairs', $tester->getDisplay());
        self::assertCount(1, self::getContainer()->get(PlausibilityRepositoryInterface::class)->snapshots(), 'only the seed draft');
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('app:encounter:learn-plausibility'));
    }
}
