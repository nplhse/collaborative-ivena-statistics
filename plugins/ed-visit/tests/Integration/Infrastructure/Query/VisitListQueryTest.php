<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Integration\Infrastructure\Query;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EffectiveAssignments;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use Cois\Plugin\EdVisit\Application\Review\ReviewPeriod;
use Cois\Plugin\EdVisit\Application\VisitList\VisitLinkState;
use Cois\Plugin\EdVisit\Application\VisitList\VisitListFilter;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterMatch;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterTimes;
use Cois\Plugin\EdVisit\Domain\Entity\Triage;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalMode;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalRoute;
use Cois\Plugin\EdVisit\Domain\Enum\MatchStatus;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCategory;
use Cois\Plugin\EdVisit\Infrastructure\Query\VisitListQuery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Visit list (#1178) of hospital A on 12 October 2026:
 * - A1 09:00, two open proposals and one rejected pair,
 * - A2 10:00, linked automatically and one rejected pair,
 * - A3 11:00, only a rejected pair, self-referral,
 * - A4 12:00, no candidate, MTS red,
 * - A5 on 13 October (outside the period) and B1 of hospital B on 12 October are never listed.
 *
 * Expected: 4 rows, one per visit. With REVIEW: A1 pending, A2 linked, A3 and A4 unlinked; without REVIEW
 * A1 is unlinked too.
 */
#[ResetDatabase]
final class VisitListQueryTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->encounterHospital();
        $other = $this->encounterHospital();
        $reviewer = $this->user();

        $visits = $this->encounterImport($this->hospital, 'List visits');
        $allocations = $this->encounterImport($this->hospital, 'List allocations', ImportType::ALLOCATION);
        $allocation = fn (string $time): Allocation => $this->allocationAt($this->hospital, $allocations, $time);

        $a1 = $this->visitAt($this->hospital, $visits, '09:00');
        $a2 = $this->visitAt($this->hospital, $visits, '10:00');
        $a3 = $this->visitAt($this->hospital, $visits, '11:00', mode: ArrivalMode::Self);
        $a4 = $this->visit($this->hospital, $visits, '2026-10-12 12:00', 'a4', MtsCategory::Red);
        $this->visit($this->hospital, $visits, '2026-10-13 10:00', 'a5');
        $this->visit($other, $this->encounterImport($other, 'Other visits'), '2026-10-12 10:00', 'b1');

        $this->pair($allocation('08:50'), $a1, MatchStatus::Proposed);
        $this->pair($allocation('08:55'), $a1, MatchStatus::Proposed);
        $this->pair($allocation('08:45'), $a1, MatchStatus::Rejected, $reviewer);
        $this->pair($allocation('09:55'), $a2, MatchStatus::AutoAccepted);
        $this->pair($allocation('09:50'), $a2, MatchStatus::Rejected, $reviewer);
        $this->pair($allocation('10:55'), $a3, MatchStatus::Rejected, $reviewer);
        self::assertGreaterThan(0, $a4);
    }

    public function testOneRowPerVisitWithItsLinkState(): void
    {
        $rows = $this->query()->visits($this->filter(true), 'anchor', 'asc', 0, 50);

        self::assertSame(
            ['09:00 pending', '10:00 linked', '11:00 unlinked', '12:00 unlinked'],
            array_map(static fn (array $row): string => $row['anchor_at']->format('H:i').' '.$row['link_state'], $rows),
        );
        self::assertNotNull($rows[1]['allocation_public_id'], 'The linked visit carries its allocation.');
        self::assertNull($rows[0]['allocation_id'], 'Proposals are never joined.');
        self::assertSame(4, $this->query()->countVisits($this->filter(true)));
    }

    public function testWithoutReviewOpenProposalsCountAsUnlinked(): void
    {
        $rows = $this->query()->visits($this->filter(false), 'anchor', 'asc', 0, 50);

        $states = array_count_values(array_column($rows, 'link_state'));
        ksort($states);
        self::assertSame(['linked' => 1, 'unlinked' => 3], $states);
        self::assertSame(3, $this->query()->countVisits($this->filter(false, linkState: VisitLinkState::Unlinked)));
        self::assertSame(4, $this->query()->countVisits($this->filter(false, linkState: VisitLinkState::Pending)), 'The pending filter is dropped without REVIEW.');
    }

    public function testLinkStateFilterWithReview(): void
    {
        self::assertSame(1, $this->query()->countVisits($this->filter(true, linkState: VisitLinkState::Linked)));
        self::assertSame(1, $this->query()->countVisits($this->filter(true, linkState: VisitLinkState::Pending)));
        self::assertSame(2, $this->query()->countVisits($this->filter(true, linkState: VisitLinkState::Unlinked)));
    }

    public function testPagesSplitTheRowsWithoutDuplicates(): void
    {
        $first = $this->query()->visits($this->filter(true), 'anchor', 'desc', 0, 3);
        $second = $this->query()->visits($this->filter(true), 'anchor', 'desc', 3, 3);

        self::assertCount(3, $first);
        self::assertCount(1, $second);
        $ids = [...array_column($first, 'encounter_id'), ...array_column($second, 'encounter_id')];
        self::assertCount(4, array_unique($ids));
        self::assertCount($this->query()->countVisits($this->filter(true)), $ids);
        self::assertSame('12:00', $first[0]['anchor_at']->format('H:i'), 'Newest first.');
    }

    public function testRouteAndCategoryFilters(): void
    {
        self::assertSame(1, $this->query()->countVisits($this->filter(true, routes: [ArrivalRoute::Self])));
        self::assertSame(3, $this->query()->countVisits($this->filter(true, routes: [ArrivalRoute::Ems])));
        self::assertSame(1, $this->query()->countVisits($this->filter(true, categories: [MtsCategory::Red])));
    }

    public function testHospitalChoices(): void
    {
        self::assertSame([], $this->query()->hospitals([]));
        $choices = $this->query()->hospitals([(int) $this->hospital->getId()]);
        self::assertCount(1, $choices);
        self::assertSame($this->hospital->getPublicIdString(), $choices[0]['public_id']);
        self::assertCount(2, $this->query()->hospitals(null), 'Every hospital with visits.');
    }

    public function testVisitUidsOfGivenEncountersOnly(): void
    {
        $connection = $this->encounterEntityManager()->getConnection();
        /** @var array<int|string, string> $expected */
        $expected = $connection->fetchAllKeyValue('SELECT encounter_id, visit_uid FROM ed_visit WHERE hospital_id = ? ORDER BY encounter_id LIMIT 2', [$this->hospital->getId()]);
        $ids = array_map(intval(...), array_keys($expected));

        $uids = $this->query()->visitUids([...$ids, 999999]);
        ksort($uids);

        self::assertSame(array_combine($ids, array_values($expected)), $uids, 'An id without ED visit gets no uid.');
        self::assertSame([], $this->query()->visitUids([]));
    }

    /**
     * @param list<ArrivalRoute> $routes
     * @param list<MtsCategory>  $categories
     */
    private function filter(bool $includeProposals, ?VisitLinkState $linkState = null, array $routes = [], array $categories = []): VisitListFilter
    {
        $day = new \DateTimeImmutable('2026-10-12');

        return new VisitListFilter((int) $this->hospital->getId(), new ReviewPeriod($day, $day), $includeProposals, $routes, $linkState, $categories);
    }

    private function query(): VisitListQuery
    {
        return new VisitListQuery($this->encounterEntityManager()->getConnection());
    }

    private function visit(Hospital $hospital, Import $import, string $admission, string $key, ?MtsCategory $category = null): int
    {
        $id = $this->encounterRepository()->insertIfNewReturningId(new EdVisit(
            hospital: $hospital,
            import: $import,
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'list|'.$key),
            caseKey: hash('sha256', 'list-case|'.$key),
            age: 60,
            sex: Sex::Male,
            times: new EncounterTimes(edAdmissionAt: new \DateTimeImmutable($admission)),
            arrivalMode: ArrivalMode::Ambulance,
            triage: new Triage($category),
        ));
        self::assertNotNull($id);

        return $id;
    }

    private function pair(Allocation $allocation, int $encounterId, MatchStatus $status, ?User $decidedBy = null): void
    {
        $em = $this->encounterEntityManager();
        $encounter = $em->find(Encounter::class, $encounterId);
        $managed = $em->find(Allocation::class, $allocation->getId());
        self::assertInstanceOf(Encounter::class, $encounter);
        self::assertInstanceOf(Allocation::class, $managed);

        $initial = MatchStatus::AutoAccepted === $status ? $status : MatchStatus::Proposed;
        $match = new EncounterMatch($managed, $encounter, $initial, AnchorSource::Admission, 5, new \DateTimeImmutable('2026-10-12 13:00'), 0.8, 8, [], 1, '1.2.0');
        if ($decidedBy instanceof User) {
            $user = $em->find(User::class, $decidedBy->getId());
            self::assertInstanceOf(User::class, $user);
            $match->decide($status, $user, new \DateTimeImmutable('2026-10-12 14:00'));
        }
        $em->persist($match);
        $em->flush();
        EffectiveAssignments::syncFromMatches($em->getConnection());
    }
}
