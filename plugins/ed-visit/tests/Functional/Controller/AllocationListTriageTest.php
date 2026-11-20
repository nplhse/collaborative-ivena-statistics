<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Functional\Controller;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EffectiveAssignments;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use Cois\Plugin\EdVisit\Application\Contract\VisitListReaderInterface;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterMatch;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterTimes;
use Cois\Plugin\EdVisit\Domain\Entity\Triage;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalMode;
use Cois\Plugin\EdVisit\Domain\Enum\MatchStatus;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCategory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Triage columns of the Explore allocation list (#1203): category, diagram and indicator, picked on purpose and loaded
 * after the page. Hospital A grants the user VIEW_CASES, hospital B does not; both have an ED visit linked to an
 * allocation. Each encounter id is checked by its own hospital, so asking for B's encounter returns nothing.
 */
#[ResetDatabase]
final class AllocationListTriageTest extends WebTestCase
{
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private const string CELLS = '/explore/allocation/encounter-cells';

    private const string TRIAGE_COLUMNS = 'ed_visit_triage_category,ed_visit_triage_diagram,ed_visit_triage_indicator';

    private KernelBrowser $client;

    private User $user;

    private int $encounterA;

    private int $encounterB;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->user = $this->user();
        $hospitalA = $this->hospital($this->user);
        $hospitalB = $this->hospital(null);
        $this->hospitalGrant($hospitalB, $this->user);
        $this->encounterGrant($hospitalA, $this->user, [EncounterGrantAction::ViewCases]);

        $this->encounterA = $this->linkedVisit($hospitalA, 'a');
        $this->encounterB = $this->linkedVisit($hospitalB, 'b');
    }

    public function testTriageColumnsArePickedOnPurposeAndRenderAPlaceholderForVisibleHospitalsOnly(): void
    {
        $this->client->loginUser($this->user);

        // The filter "with" shows the link column only.
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation?encounter=with');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="allocation-list-encounter-cell"]'));
        self::assertSelectorExists('[data-testid="allocation-list-encounter-link"]');

        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation?columns=timestamps,encounter,ed_visit_triage_category,ed_visit_triage_diagram');
        self::assertResponseIsSuccessful();
        $placeholders = $crawler->filter('[data-testid="allocation-list-encounter-cell"]');
        self::assertCount(2, $placeholders, 'Category and diagram of hospital A; nothing for hospital B.');
        self::assertSame([(string) $this->encounterA, (string) $this->encounterA], $placeholders->each(static fn ($node): ?string => $node->attr('data-encounter-list-cell-id-value')));
        self::assertSame(['ed_visit_triage_category', 'ed_visit_triage_diagram'], $placeholders->each(static fn ($node): ?string => $node->attr('data-encounter-list-cell-column-value')));
        self::assertSame(self::CELLS, $placeholders->attr('data-encounter-list-cell-url-value'));
    }

    public function testCellsAreReturnedOnlyForEncountersOfHospitalsWithCaseAccess(): void
    {
        $this->client->loginUser($this->user);

        $cells = $this->cells($this->encounterA.','.$this->encounterB, self::TRIAGE_COLUMNS.',unknown');

        self::assertSame([$this->encounterA], array_keys($cells));
        self::assertSame(['ed_visit_triage_category', 'ed_visit_triage_diagram', 'ed_visit_triage_indicator'], array_keys($cells[$this->encounterA]));
        // The category is a badge in its colour with its name; codes resolve to catalog names like on the visit list.
        self::assertStringContainsString('class="badge bg-orange text-orange-fg"', $cells[$this->encounterA]['ed_visit_triage_category']);
        self::assertStringContainsString('Very urgent', $cells[$this->encounterA]['ed_visit_triage_category']);
        self::assertStringContainsString('Chest pain', $cells[$this->encounterA]['ed_visit_triage_diagram']);
        self::assertStringContainsString('Heiß', $cells[$this->encounterA]['ed_visit_triage_indicator']);
        // Case data must not be stored by browsers or proxies.
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
    }

    public function testOnlyTheRequestedColumnsAreAnswered(): void
    {
        $this->client->loginUser($this->user);

        self::assertSame(['ed_visit_triage_diagram'], array_keys($this->cells((string) $this->encounterA, 'ed_visit_triage_diagram')[$this->encounterA]));
        self::assertSame([], $this->cells((string) $this->encounterA, 'unknown'));
    }

    public function testVisitsWithoutTriageValuesGetNoCells(): void
    {
        $hospitalA = $this->hospitalOfEncounter($this->encounterA);
        $visits = $this->encounterImport($hospitalA, 'Visits without triage');
        $withoutTriage = $this->visitAt($hospitalA, $visits, '12:00');
        $this->client->loginUser($this->user);

        self::assertSame([$this->encounterA], array_keys($this->cells($this->encounterA.','.$withoutTriage, self::TRIAGE_COLUMNS)));
        self::assertSame([], self::getContainer()->get(VisitListReaderInterface::class)->triages([]));
    }

    public function testUsersWithoutCaseAccessGetNoCells(): void
    {
        $other = $this->user();
        $this->hospitalGrant($this->hospitalOfEncounter($this->encounterA), $other);
        $this->client->loginUser($other);

        self::assertSame([], $this->cells((string) $this->encounterA, self::TRIAGE_COLUMNS));
    }

    public function testInvalidIdsAreIgnored(): void
    {
        $this->client->loginUser($this->user);

        self::assertSame([], $this->cells('abc,-1,0,999999', self::TRIAGE_COLUMNS));
        self::assertSame([$this->encounterA], array_keys($this->cells($this->encounterA.',x,'.$this->encounterA, self::TRIAGE_COLUMNS)));
    }

    public function testAnonymousUsersAreSentToTheLogin(): void
    {
        $this->client->request(Request::METHOD_GET, self::CELLS.'?encounters='.$this->encounterA.'&columns='.self::TRIAGE_COLUMNS);

        self::assertResponseRedirects();
    }

    /**
     * @return array<int, array<string, string>> html by encounter id and column (JSON object keys decode to integers)
     */
    private function cells(string $ids, string $columns): array
    {
        $this->client->request(Request::METHOD_GET, self::CELLS.'?encounters='.$ids.'&columns='.$columns);
        self::assertResponseIsSuccessful();

        /** @var array{cells: array<int, array<string, string>>} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $body['cells'];
    }

    private function linkedVisit(Hospital $hospital, string $case): int
    {
        $visits = $this->encounterImport($hospital, 'Visits '.$case);
        $allocations = $this->encounterImport($hospital, 'Allocations '.$case, ImportType::ALLOCATION);
        $allocation = $this->allocationAt($hospital, $allocations, '10:00');

        $encounterId = (int) $this->encounterRepository()->insertIfNewReturningId(new EdVisit(
            hospital: $hospital,
            import: $visits,
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'list|'.$case),
            caseKey: hash('sha256', 'list|'.$case),
            age: 70,
            sex: Sex::Female,
            times: new EncounterTimes(edAdmissionAt: new \DateTimeImmutable('2026-10-12 10:07')),
            arrivalMode: ArrivalMode::Ambulance,
            triage: new Triage(MtsCategory::Orange, 'thoraxschmerz', 'heiss', 6),
        ));
        $this->pair($allocation, $encounterId, MatchStatus::AutoAccepted);

        return $encounterId;
    }

    private function pair(Allocation $allocation, int $encounterId, MatchStatus $status): void
    {
        $em = $this->encounterEntityManager();
        $encounter = $em->find(Encounter::class, $encounterId);
        $managed = $em->find(Allocation::class, $allocation->getId());
        self::assertInstanceOf(Encounter::class, $encounter);
        self::assertInstanceOf(Allocation::class, $managed);

        $em->persist(new EncounterMatch($managed, $encounter, $status, AnchorSource::Admission, 7, new \DateTimeImmutable('2026-10-12 11:00'), 0.9697, 9, [], 1, '1.0.0'));
        $em->flush();
        EffectiveAssignments::syncFromMatches($em->getConnection());
    }

    private function hospitalOfEncounter(int $encounterId): Hospital
    {
        $hospitalId = (int) $this->entityManager()->getConnection()->fetchOne('SELECT hospital_id FROM encounter WHERE id = ?', [$encounterId]);
        $hospital = $this->entityManager()->find(Hospital::class, $hospitalId);
        self::assertInstanceOf(Hospital::class, $hospital);

        return $hospital;
    }
}
