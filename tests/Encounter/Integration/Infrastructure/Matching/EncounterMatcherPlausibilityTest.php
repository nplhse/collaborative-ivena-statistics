<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Matching;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Encounter\Application\Catalog\MtsCatalogCuration;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Plausibility\PlausibilityLookup;
use App\Encounter\Application\Plausibility\PlausibilitySnapshots;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\MtsAlias;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\MtsCatalogKind;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Matcher 1.2.0 with the plausibility table against the database (ADR 030 amendment, ADR 033, #1154).
 *
 * The seed snapshot of the migration puts PZC 711 into the problem area "urologie" with the diagram
 * `urologisches_problem` at +3 (area cell; the exact cells have no observations). An allocation at 10:00 (PZC 711,
 * female, 70) and a visit at 10:07 (male, 70, ambulance) score 4 − 2 + 2 + 1 = 5 points: 32 / 48 = 0.6667, a
 * proposal of pass 1. With the bonus in pass 2: 8 points, 256 / 272 = 0.9412, an automatic link the bonus decided.
 */
#[ResetDatabase]
final class EncounterMatcherPlausibilityTest extends KernelTestCase
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

    public function testWithoutActiveSnapshotTheSexMismatchStaysAProposal(): void
    {
        $hospital = $this->encounterHospital();
        $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '10:00', pzc: 711);
        $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), '10:07', sex: Sex::Male, diagram: 'urologisches_problem');

        $result = $this->matchDay($hospital);

        self::assertSame([0, 1, 0], [$result->autoAccepted, $result->proposed, $result->plausibilityDecisive]);
        $match = $this->onlyMatch();
        self::assertSame([MatchStatus::Proposed, 1, 5, 0.6667], [$match->getStatus(), $match->getPass(), $match->getPoints(), $match->getScore()]);
        self::assertArrayNotHasKey('plausibility', $match->getContributions());
        self::assertSame([null, null, false], [$match->getPlausibilitySnapshot(), $match->getPlausibilityLevel(), $match->isPlausibilityDecisive()]);
        self::assertSame('1.2.0', $match->getMatcherVersion());
    }

    public function testTheActiveSnapshotLinksThePairInPassTwoAndRecordsTheBonus(): void
    {
        $snapshot = $this->activateSeed();
        $hospital = $this->encounterHospital();
        $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '10:00', pzc: 711);
        $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), '10:07', sex: Sex::Male, diagram: 'urologisches_problem');

        $result = $this->matchDay($hospital);

        self::assertSame([1, 0, 1], [$result->autoAccepted, $result->proposed, $result->plausibilityDecisive]);
        $match = $this->onlyMatch();
        self::assertSame([MatchStatus::AutoAccepted, 2, 8, 0.9412], [$match->getStatus(), $match->getPass(), $match->getPoints(), $match->getScore()]);
        self::assertSame(['time' => 4, 'sex' => -2, 'age' => 2, 'arrival_route' => 1, 'physician' => 0, 'work_accident' => 0, 'plausibility' => 3], $match->getContributions());
        self::assertSame($snapshot->getId(), $match->getPlausibilitySnapshot()?->getId());
        self::assertSame(['area', true], [$match->getPlausibilityLevel(), $match->isPlausibilityDecisive()]);
    }

    public function testAPassOneLinkKeepsNoBonusUnderAnActiveSnapshot(): void
    {
        $this->activateSeed();
        $hospital = $this->encounterHospital();
        $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '10:00', pzc: 711);
        $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), '10:07', diagram: 'urologisches_problem');

        $this->matchDay($hospital);

        $match = $this->onlyMatch();
        self::assertSame([MatchStatus::AutoAccepted, 1, 9], [$match->getStatus(), $match->getPass(), $match->getPoints()]);
        self::assertArrayNotHasKey('plausibility', $match->getContributions());
        self::assertSame([null, null, false], [$match->getPlausibilitySnapshot(), $match->getPlausibilityLevel(), $match->isPlausibilityDecisive()]);
    }

    public function testAnAliasDiagramCodeResolvesToItsCanonicalCell(): void
    {
        $this->activateSeed();
        $curation = self::getContainer()->get(MtsCatalogCuration::class);
        self::assertInstanceOf(MtsCatalogCuration::class, $curation);
        $alias = new MtsAlias(MtsCatalogKind::Diagram, '', 'Harnwegsproblem', 'urologisches_problem');
        $curation->createAlias($alias);
        self::getContainer()->get(PlausibilityLookup::class)->reset();

        $hospital = $this->encounterHospital();
        $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '10:00', AllocationGender::FEMALE, pzc: 711);
        $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), '10:07', sex: Sex::Male, diagram: $alias->getCode());

        $this->matchDay($hospital);

        $match = $this->onlyMatch();
        self::assertSame([MatchStatus::AutoAccepted, 3, 'area'], [$match->getStatus(), $match->getContributions()['plausibility'] ?? null, $match->getPlausibilityLevel()]);
    }

    private function activateSeed(): PlausibilitySnapshot
    {
        $repository = self::getContainer()->get(PlausibilityRepositoryInterface::class);
        self::assertInstanceOf(PlausibilityRepositoryInterface::class, $repository);
        $seed = $repository->seedDraft();
        self::assertInstanceOf(PlausibilitySnapshot::class, $seed);

        $snapshots = self::getContainer()->get(PlausibilitySnapshots::class);
        self::assertInstanceOf(PlausibilitySnapshots::class, $snapshots);
        $snapshots->activate($seed);

        return $seed;
    }

    private function onlyMatch(): EncounterMatch
    {
        $this->encounterEntityManager()->clear();
        $matches = $this->encounterEntityManager()->getRepository(EncounterMatch::class)->findAll();
        self::assertCount(1, $matches);

        return $matches[0];
    }
}
