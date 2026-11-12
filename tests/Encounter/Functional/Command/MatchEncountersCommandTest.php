<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Command;

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
 * `app:encounter:match` for a hospital and period (#1053).
 */
#[ResetDatabase]
final class MatchEncountersCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    public function testMatchesThePeriodAndPrintsCounts(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-12 09:00:00');
        $hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $ed = $this->encounterImport($hospital, 'ED');
        $allocation = $this->allocationAt($hospital, $ivena, '10:00');
        $visit = $this->visitAt($hospital, $ed, '10:05');

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute(['--hospital' => (string) $hospital->getId(), '--from' => '2026-10-11', '--to' => '2026-10-13']));
        self::assertMatchesRegularExpression('/\s1\s+1\s+1\s+0\s+0\s/', $tester->getDisplay());
        self::assertSame([sprintf('%d-%d auto_accepted 0.9697', $allocation->getId(), $visit)], $this->matchRows($hospital));

        // A second run changes nothing.
        self::assertSame(Command::SUCCESS, $tester->execute(['--hospital' => (string) $hospital->getId(), '--from' => '2026-10-12']));
        self::assertMatchesRegularExpression('/\s1\s+1\s+0\s+0\s+0\s/', $tester->getDisplay());
    }

    public function testRefusesAMissingHospitalOrPeriod(): void
    {
        self::bootKernel();
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute(['--from' => '2026-10-12']));
        self::assertSame(Command::INVALID, $tester->execute(['--hospital' => '1', '--from' => '2026-10-12', '--to' => '2026-10-11']));
        self::assertSame(Command::INVALID, $tester->execute(['--hospital' => '1', '--from' => '12.10.2026']));
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester(new Application(self::$kernel)->find('app:encounter:match'));
    }
}
