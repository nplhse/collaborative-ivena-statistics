<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Command;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Review\ReviewPeriod;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;
use App\Encounter\Infrastructure\Validation\MatchValidator;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `app:encounter:evaluate-matcher` (#1057): a read-only replay with changed points or thresholds,
 * compared with validation verdicts or a synthetic truth.
 *
 * 12 October 2026: 10:00 → visit 10:05 (9 points, 0.9697, automatic); 12:00 → visit 12:20 at age 71
 * (time +2, sex +2, age +1, route +1 = 6 points, 64 / 80 = 0.80, proposal).
 */
#[ResetDatabase]
final class EvaluateMatcherCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-12 09:00:00');
        $this->hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($this->hospital, 'IVENA', ImportType::ALLOCATION);
        $ed = $this->encounterImport($this->hospital, 'ED');
        $this->allocationAt($this->hospital, $ivena, '10:00');
        $this->allocationAt($this->hospital, $ivena, '12:00');
        $this->visitAt($this->hospital, $ed, '10:05');
        $this->visitAt($this->hospital, $ed, '12:20', age: 71);
    }

    public function testReplaysWithChangedPointsAndWritesNothing(): void
    {
        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute($this->period()));
        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/automatic\s+0\.90–0\.99\s+1\s+0\s+0\s+1\s+–/u', $display);
        self::assertMatchesRegularExpression('/proposal\s+0\.70–0\.90\s+1\s+0\s+0\s+1\s+–/u', $display);
        self::assertStringContainsString('validation verdicts', $display);

        // Age difference 1 worth 3: 8 points, 256 / 272 = 0.9412, both automatic.
        self::assertSame(Command::SUCCESS, $tester->execute([...$this->period(), '--points' => ['age.1=3'], '--label' => 'age-3']));
        self::assertMatchesRegularExpression('/automatic\s+0\.90–0\.99\s+2\s/u', $tester->getDisplay());
        self::assertStringContainsString('age-3 (no match 4)', $tester->getDisplay());

        // A stricter auto-accept threshold turns the 0.9697 link into a proposal.
        self::assertSame(Command::SUCCESS, $tester->execute([...$this->period(), '--auto-accept' => '0.98']));
        self::assertMatchesRegularExpression('/proposal\s+0\.90–0\.99\s+1\s/u', $tester->getDisplay());

        self::assertSame([], $this->matchRows($this->hospital));
    }

    public function testComparesWithVerdicts(): void
    {
        $this->matchDay($this->hospital);
        $validator = self::getContainer()->get(MatchValidator::class);
        self::assertInstanceOf(MatchValidator::class, $validator);
        $sample = $validator->draw((int) $this->hospital->getId(), new ReviewPeriod(new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-12')), 5, $this->user(), 7);
        foreach ($this->encounterEntityManager()->getRepository(MatchValidationItem::class)->findBy(['sample' => $sample]) as $item) {
            if (ValidationStratum::Automatic === $item->getStratum()) {
                $validator->record($item, ValidationVerdict::Correct, $this->user());
            }
        }

        $tester = $this->tester();
        self::assertSame(Command::SUCCESS, $tester->execute($this->period()));
        self::assertMatchesRegularExpression('/automatic\s+0\.90–0\.99\s+1\s+1\s+0\s+0\s+1\.000/u', $tester->getDisplay());
    }

    public function testComparesWithASyntheticTruth(): void
    {
        /** @var list<array{id: int, visit_fingerprint: string}> $visits */
        $visits = $this->encounterEntityManager()->getConnection()->fetchAllAssociative('SELECT id, visit_fingerprint FROM encounter ORDER BY id');
        $allocations = $this->encounterEntityManager()->getConnection()->fetchFirstColumn('SELECT id FROM allocation ORDER BY id');
        $file = sys_get_temp_dir().'/evaluate-matcher-truth-'.bin2hex(random_bytes(4)).'.json';
        new Filesystem()->dumpFile($file, json_encode(['format' => 'synthetic-encounter-truth', 'version' => 1, 'visits' => [
            ['visit_fingerprints' => [$visits[0]['visit_fingerprint']], 'allocation_id' => (int) $allocations[0]],
            ['visit_fingerprints' => [$visits[1]['visit_fingerprint']], 'allocation_id' => (int) $allocations[1]],
        ]], \JSON_THROW_ON_ERROR));

        try {
            $tester = $this->tester();
            self::assertSame(Command::SUCCESS, $tester->execute([...$this->period(), '--truth' => $file]));
        } finally {
            new Filesystem()->remove($file);
        }

        $display = $tester->getDisplay();
        self::assertStringContainsString('synthetic truth', $display);
        self::assertMatchesRegularExpression('/proposal\s+0\.70–0\.90\s+1\s+1\s+0\s+0\s+1\.000/u', $display);
        // Two true pairs: one automatic, both found.
        self::assertMatchesRegularExpression('/\s2\s+1 \(0\.500\)\s+2 \(1\.000\)/', $display);
    }

    public function testRefusesUnknownPoints(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute([...$this->period(), '--points' => ['time.never=1']]));
        self::assertSame(Command::INVALID, $tester->execute([...$this->period(), '--points' => ['time.near']]));
        self::assertSame(Command::INVALID, $tester->execute(['--from' => '2026-10-12']));
    }

    /**
     * @return array{'--hospital': string, '--from': string}
     */
    private function period(): array
    {
        return ['--hospital' => (string) $this->hospital->getId(), '--from' => '2026-10-12'];
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester(new Application(self::$kernel)->find('app:encounter:evaluate-matcher'));
    }
}
