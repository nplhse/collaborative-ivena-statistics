<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Matching;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Encounter\Application\Contract\EncounterKeyChangeSummaryReaderInterface;
use App\Encounter\Application\Event\EncountersImported;
use App\Encounter\Application\Matching\MatchPlanner;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Matcher v1 against the database (ADR 030, #1053): stored explanation, reruns, manual decisions,
 * conflicts, deletion and the triggers. Every visit scores 9 points against an allocation at its
 * arrival time unless stated otherwise (score 512 / 528 = 0.9697).
 */
#[ResetDatabase]
final class EncounterMatcherTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-11-12 09:00:00');
    }

    public function testAnAutomaticLinkStoresHowItWasFound(): void
    {
        $hospital = $this->encounterHospital();
        $allocation = $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '10:00');
        $visit = $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), '10:07');

        $result = $this->matchDay($hospital);

        self::assertSame([1, 1, 1, 0, 0], [$result->allocations, $result->encounters, $result->autoAccepted, $result->proposed, $result->conflicts]);
        $match = $this->onlyMatch();
        self::assertSame($allocation->getId(), $match->getAllocation()->getId());
        self::assertSame($visit, $match->getEncounter()->getId());
        self::assertSame(MatchStatus::AutoAccepted, $match->getStatus());
        self::assertSame(0.9697, $match->getScore());
        self::assertSame(9, $match->getPoints());
        self::assertSame(['time' => 4, 'sex' => 2, 'age' => 2, 'arrival_route' => 1, 'physician' => 0, 'work_accident' => 0], $match->getContributions());
        self::assertSame(1, $match->getPass());
        self::assertSame(MatchPlanner::VERSION, $match->getMatcherVersion());
        self::assertSame('1.1.0', $match->getMatcherVersion());
        self::assertSame(AnchorSource::Admission, $match->getTimeAnchor());
        self::assertSame(7, $match->getTimeDifferenceMinutes());
        self::assertNull($match->getDecidedAt());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 09:00:00'), $match->getCreatedAt());
    }

    public function testTheFirstAssessmentContactIsAMarkedSubstituteAnchor(): void
    {
        $hospital = $this->encounterHospital();
        $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '10:00');
        // No ED admission: the first assessment contact at 10:12 is used (12 minutes, time +4).
        $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), null, '10:12');

        $this->matchDay($hospital);

        self::assertSame(AnchorSource::Assessment, $this->onlyMatch()->getTimeAnchor());
        self::assertSame(12, $this->onlyMatch()->getTimeDifferenceMinutes());
    }

    public function testARerunNeverChangesAnExistingLink(): void
    {
        $hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $ed = $this->encounterImport($hospital, 'ED');
        $allocation = $this->allocationAt($hospital, $ivena, '10:00');
        // 10:20 at age 71: time +3, sex +2, age +1, route +1 = 7 points alone: 128 / 144 = 0.8889, a proposal.
        $first = $this->visitAt($hospital, $ed, '10:20', age: 71);
        $this->matchDay($hospital);
        self::assertSame([sprintf('%d-%d proposed 0.8889', $allocation->getId(), $first)], $this->matchRows($hospital));

        // Proposals are recomputed: with a better visit at 10:01 (9 points) the old proposal drops to
        // 128 / (16 + 512 + 128) = 0.1951 and the new one gets 512 / 656 = 0.7805.
        $second = $this->visitAt($hospital, $ed, '10:01');
        $this->matchDay($hospital);
        self::assertSame([sprintf('%d-%d proposed 0.7805', $allocation->getId(), $second)], $this->matchRows($hospital));

        // Once linked, a new visit that would fit better changes nothing.
        $this->matchRowsAfterConfirming($allocation, $second);
        $third = $this->visitAt($hospital, $ed, '10:00', sex: Sex::Female);
        $result = $this->matchDay($hospital);

        self::assertSame([0, 0], [$result->autoAccepted, $result->proposed]);
        self::assertSame([sprintf('%d-%d confirmed 0.7805', $allocation->getId(), $second)], $this->matchRows($hospital));
        self::assertNotSame($second, $third);
    }

    public function testAnAutomaticLinkSurvivesARerunWithABetterVisit(): void
    {
        $hospital = $this->encounterHospital();
        $ed = $this->encounterImport($hospital, 'ED');
        $allocation = $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '10:00');
        $linked = $this->visitAt($hospital, $ed, '10:14', age: 71);
        $this->matchDay($hospital);
        // 4 + 2 + 1 + 1 = 8 points: 256 / 272 = 0.9412.
        $expected = [sprintf('%d-%d auto_accepted 0.9412', $allocation->getId(), $linked)];
        self::assertSame($expected, $this->matchRows($hospital));

        $this->visitAt($hospital, $ed, '10:02');
        $this->matchDay($hospital);
        $this->matchDay($hospital);

        self::assertSame($expected, $this->matchRows($hospital));
    }

    public function testRejectedPairsAreNeverProposedAgainAndConfirmedLinksStay(): void
    {
        $hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $ed = $this->encounterImport($hospital, 'ED');
        $allocation = $this->allocationAt($hospital, $ivena, '10:00');
        $visit = $this->visitAt($hospital, $ed, '10:05');
        $this->matchDay($hospital);
        $this->decide($allocation, $visit, MatchStatus::Rejected);

        $result = $this->matchDay($hospital);

        self::assertSame([0, 0], [$result->autoAccepted, $result->proposed]);
        self::assertSame([sprintf('%d-%d rejected 0.9697', $allocation->getId(), $visit)], $this->matchRows($hospital));

        // A second allocation may still take the visit.
        $other = $this->allocationAt($hospital, $ivena, '10:03');
        $this->matchDay($hospital);
        self::assertSame([
            sprintf('%d-%d rejected 0.9697', $allocation->getId(), $visit),
            sprintf('%d-%d auto_accepted 0.9697', $other->getId(), $visit),
        ], $this->matchRows($hospital));
    }

    public function testTwoAllocationsClaimingOneVisitGoToReviewWithoutLink(): void
    {
        $hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $first = $this->allocationAt($hospital, $ivena, '10:00');
        $second = $this->allocationAt($hospital, $ivena, '10:04');
        $visit = $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), '10:06');

        $result = $this->matchDay($hospital);

        self::assertSame([0, 2, 2], [$result->autoAccepted, $result->proposed, $result->conflicts]);
        self::assertSame([
            sprintf('%d-%d proposed 0.9697 conflict', $first->getId(), $visit),
            sprintf('%d-%d proposed 0.9697 conflict', $second->getId(), $visit),
        ], $this->matchRows($hospital));
    }

    public function testNewAllocationsOnlyFillGaps(): void
    {
        $hospital = $this->encounterHospital();
        $ed = $this->encounterImport($hospital, 'ED');
        $first = $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA 1', ImportType::ALLOCATION), '10:00');
        $linked = $this->visitAt($hospital, $ed, '10:05');
        $free = $this->visitAt($hospital, $ed, '18:05', sex: Sex::Male, age: 40);
        $this->matchDay($hospital);

        $second = $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA 2', ImportType::ALLOCATION), '18:00', AllocationGender::MALE, 40);
        $this->dispatch(new ImportCompleted((int) $second->getImport()?->getId()));

        self::assertSame([
            sprintf('%d-%d auto_accepted 0.9697', $first->getId(), $linked),
            sprintf('%d-%d auto_accepted 0.9697', $second->getId(), $free),
        ], $this->matchRows($hospital));
    }

    public function testEncountersImportedMatchesTheDaysAroundTheNewVisits(): void
    {
        $hospital = $this->encounterHospital();
        $ed = $this->encounterImport($hospital, 'ED');
        // Arrival at 23:55 on the 11th, ED admission at 00:05 on the 12th.
        $allocation = $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION), '00:00');
        $this->encounterEntityManager()->getConnection()->executeStatement("UPDATE allocation SET arrival_at = '2026-10-11 23:55:00' WHERE id = ?", [$allocation->getId()]);
        $visit = $this->visitAt($hospital, $ed, '00:05');

        $this->dispatch(new EncountersImported((int) $hospital->getId(), (int) $ed->getId(), ['2026-10-12']));

        self::assertSame([sprintf('%d-%d auto_accepted 0.9697', $allocation->getId(), $visit)], $this->matchRows($hospital));
    }

    public function testDeletingAnAllocationImportFreesItsVisitsForTheRemainingAllocations(): void
    {
        $hospital = $this->encounterHospital();
        $ed = $this->encounterImport($hospital, 'ED');
        $removed = $this->encounterImport($hospital, 'IVENA removed', ImportType::ALLOCATION);
        $this->allocationAt($hospital, $removed, '10:00');
        // 10:05 − 09:44 = 21 minutes, one year apart: time +3, age +1, 7 points alone = 128 / 144 = 0.8889.
        $remaining = $this->allocationAt($hospital, $this->encounterImport($hospital, 'IVENA kept', ImportType::ALLOCATION), '09:44', age: 71);
        $visit = $this->visitAt($hospital, $ed, '10:05');
        $this->matchDay($hospital);
        self::assertCount(1, $this->matchRows($hospital));

        $this->deletionService()->delete($removed);

        self::assertSame([sprintf('%d-%d proposed 0.8889', $remaining->getId(), $visit)], $this->matchRows($hospital));
    }

    public function testDeletingTheEncounterImportRemovesItsLinksAndDecisions(): void
    {
        $hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $ed = $this->encounterImport($hospital, 'ED');
        $allocation = $this->allocationAt($hospital, $ivena, '10:00');
        $this->visitAt($hospital, $ed, '10:05');
        $this->matchDay($hospital);
        $rejected = $this->allocationAt($hospital, $ivena, '14:00');
        $visit = $this->visitAt($hospital, $ed, '14:30');
        $this->matchDay($hospital);
        $this->decide($rejected, $visit, MatchStatus::Rejected);

        $summary = $this->summary()->forHospital((int) $hospital->getId());
        self::assertSame([1, 1], [$summary['links'], $summary['decisions']]);

        $import = $this->encounterEntityManager()->find(Import::class, $ed->getId());
        self::assertInstanceOf(Import::class, $import);
        $this->deletionService()->delete($import);

        self::assertSame([], $this->matchRows($hospital));
        self::assertNotNull($this->encounterEntityManager()->find(Allocation::class, $allocation->getId()));
        $summary = $this->summary()->forHospital((int) $hospital->getId());
        self::assertSame([0, 0], [$summary['links'], $summary['decisions']]);
    }

    public function testEffectiveLinksAreOneToOneInTheDatabase(): void
    {
        $hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $first = $this->allocationAt($hospital, $ivena, '10:00');
        $second = $this->allocationAt($hospital, $ivena, '12:00');
        $visit = $this->visitAt($hospital, $this->encounterImport($hospital, 'ED'), '10:05');
        $this->matchDay($hospital);
        $encounter = $this->encounterEntityManager()->find(Encounter::class, $visit);
        self::assertInstanceOf(Encounter::class, $encounter);

        $pair = new EncounterMatch($second, $encounter, MatchStatus::Proposed, AnchorSource::Admission, -115, new \DateTimeImmutable());
        $this->encounterEntityManager()->persist($pair);
        $this->encounterEntityManager()->flush();
        $pair->decide(MatchStatus::Confirmed, UserFactory::createOne(), new \DateTimeImmutable());

        self::assertNotNull($first->getId());
        $this->expectException(UniqueConstraintViolationException::class);
        $this->encounterEntityManager()->flush();
    }

    private function matchRowsAfterConfirming(Allocation $allocation, int $encounterId): void
    {
        $this->decide($allocation, $encounterId, MatchStatus::Confirmed);
    }

    private function decide(Allocation $allocation, int $encounterId, MatchStatus $decision): void
    {
        $match = $this->encounterEntityManager()->getRepository(EncounterMatch::class)->findOneBy(['allocation' => $allocation->getId(), 'encounter' => $encounterId]);
        self::assertInstanceOf(EncounterMatch::class, $match);
        $match->decide($decision, UserFactory::createOne(), new \DateTimeImmutable('2026-11-12 09:30:00'));
        $this->encounterEntityManager()->flush();
        $this->encounterEntityManager()->clear();
    }

    private function onlyMatch(): EncounterMatch
    {
        $this->encounterEntityManager()->clear();
        $matches = $this->encounterEntityManager()->getRepository(EncounterMatch::class)->findAll();
        self::assertCount(1, $matches);

        return $matches[0];
    }

    private function dispatch(object $event): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch($event);
    }

    private function deletionService(): ImportDeletionService
    {
        $service = self::getContainer()->get(ImportDeletionService::class);
        self::assertInstanceOf(ImportDeletionService::class, $service);

        return $service;
    }

    private function summary(): EncounterKeyChangeSummaryReaderInterface
    {
        $summary = self::getContainer()->get(EncounterKeyChangeSummaryReaderInterface::class);
        self::assertInstanceOf(EncounterKeyChangeSummaryReaderInterface::class, $summary);

        return $summary;
    }
}
