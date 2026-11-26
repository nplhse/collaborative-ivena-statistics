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
use App\Tests\Fixtures\Plugin\InpatientStay\Entity\InpatientStay;
use App\Tests\Support\Encounter\EffectiveAssignments;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Entity\User;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterDisposition;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterMatch;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterTimes;
use Cois\Plugin\EdVisit\Domain\Entity\InitialVitals;
use Cois\Plugin\EdVisit\Domain\Entity\Triage;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalMode;
use Cois\Plugin\EdVisit\Domain\Enum\CareLevel;
use Cois\Plugin\EdVisit\Domain\Enum\Disposition;
use Cois\Plugin\EdVisit\Domain\Enum\IsolationCode;
use Cois\Plugin\EdVisit\Domain\Enum\MatchStatus;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCategory;
use Cois\Plugin\EdVisit\Domain\Enum\VitalsReference;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Detail page of one ED visit (#1178), on 12 October 2026:
 * - "linked": admitted 10:07, first assessment 10:12, first physician 10:40, discharged 13:07; linked
 *   automatically to an allocation expected at 10:00.
 * - "pending": admitted 14:20; two open proposals (allocations at 14:00 and 14:05), no effective link.
 * - "minimal": only a first assessment at 13:31; no triage, disposition or vitals.
 */
#[ResetDatabase]
final class ShowVisitControllerTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private KernelBrowser $client;

    private Hospital $hospital;

    private Allocation $linkedAllocation;

    private Allocation $firstProposal;

    private string $linked;

    private string $pending;

    private string $minimal;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->hospital = $this->hospital($this->user());

        $visits = $this->encounterImport($this->hospital, 'Detail visits');
        $allocations = $this->encounterImport($this->hospital, 'Detail allocations', ImportType::ALLOCATION);
        $this->linkedAllocation = $this->allocationAt($this->hospital, $allocations, '10:00');
        $this->firstProposal = $this->allocationAt($this->hospital, $allocations, '14:00');
        $secondProposal = $this->allocationAt($this->hospital, $allocations, '14:05');

        $linkedId = (int) $this->encounterRepository()->insertIfNewReturningId(new EdVisit(
            hospital: $this->hospital,
            import: $visits,
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'detail|linked'),
            caseKey: hash('sha256', 'detail|linked'),
            age: 70,
            sex: Sex::Female,
            times: new EncounterTimes(
                edAdmissionAt: new \DateTimeImmutable('2026-10-12 10:07'),
                firstAssessmentContactAt: new \DateTimeImmutable('2026-10-12 10:12'),
                firstPhysicianAt: new \DateTimeImmutable('2026-10-12 10:40'),
                edDischargeAt: new \DateTimeImmutable('2026-10-12 13:07'),
            ),
            arrivalMode: ArrivalMode::Ambulance,
            triage: new Triage(MtsCategory::Orange, 'thoraxschmerz', 'heiss', 6),
            disposition: new EncounterDisposition(Disposition::Admitted, CareLevel::Monitoring),
            vitals: new InitialVitals(VitalsReference::DocumentedInitial, bpSystolic: 145, bpDiastolic: 90),
            isolation: [IsolationCode::Mrsa],
        ));
        $pendingId = $this->visitAt($this->hospital, $visits, '14:20');
        $minimalId = $this->visitAt($this->hospital, $visits, null, '13:31', Sex::Male, 47);

        $this->pair($this->linkedAllocation, $linkedId, MatchStatus::AutoAccepted, 0.9697);
        $this->pair($this->firstProposal, $pendingId, MatchStatus::Proposed, 0.71);
        $this->pair($secondProposal, $pendingId, MatchStatus::Proposed, 0.64);

        $this->linked = $this->uid($linkedId);
        $this->pending = $this->uid($pendingId);
        $this->minimal = $this->uid($minimalId);
    }

    public function testLinkedVisitShowsEdTimesAndTheExpectedIvenaArrival(): void
    {
        $this->client->loginUser($this->caseViewer());
        $crawler = $this->visit($this->linked);

        self::assertSelectorTextContains('[data-testid="encounter-visit-title"]', 'ED visit of 12.10.2026, 10:07');
        self::assertSame('linked', $crawler->filter('[data-testid="encounter-visit-link-state"]')->attr('data-state'));
        self::assertSame('automatic', $crawler->filter('[data-testid="encounter-visit-link-state"]')->attr('data-origin'));
        self::assertSelectorTextContains('[data-testid="encounter-visit-link-agreement"]', 'Agreement 96 %');
        self::assertSame('/explore/allocation/'.$this->linkedAllocation->getPublicId(), $crawler->filter('[data-testid="encounter-visit-case-file"]')->attr('href'));
        self::assertStringNotContainsString('10:00', $crawler->filter('[data-testid="encounter-visit-link"]')->text(), 'The allocation card shows no timestamps.');
        self::assertSame(
            ['ivena_arrival', 'ed_admission', 'first_assessment', 'first_physician', 'ed_discharge'],
            $crawler->filter('[data-testid="encounter-course-event"]')->each(static fn (Crawler $event): string => (string) $event->attr('data-event')),
        );
        self::assertStringContainsString('Expected IVENA arrival', $crawler->filter('[data-event="ivena_arrival"]')->text());
        self::assertStringContainsString('-7 min', $crawler->filter('[data-event="ivena_arrival"]')->text());
        self::assertSame(
            ['5', '33', '180'],
            $this->intervals($crawler),
            'Process times start at the ED admission, not at the IVENA arrival.',
        );
        self::assertSelectorTextContains('[data-testid="encounter-course-category"]', 'Very urgent');
        self::assertSelectorTextContains('[data-testid="encounter-course-disposition"]', 'Monitoring / intermediate care');
        self::assertSelectorTextContains('[data-testid="encounter-visit-flags"]', 'MRSA');
        self::assertSelectorExists('[data-vital="blood_pressure"]');
        self::assertSelectorNotExists('[data-testid="encounter-course-vitals-reference"]', 'No label or hint for documented first values here.');
        self::assertSelectorTextNotContains('[data-testid="encounter-visit"]', 'local time', 'No note below the timeline.');
        self::assertSelectorTextContains('[data-testid="encounter-visit-basic"]', 'EMS (ambulance)');
        self::assertSelectorNotExists('[data-testid="encounter-visit-review-link"]');
    }

    public function testCaseViewerSeesAVisitWithOnlyProposalsAsNotLinked(): void
    {
        $this->client->loginUser($this->caseViewer());
        $crawler = $this->visit($this->pending);

        self::assertSame('unlinked', $crawler->filter('[data-testid="encounter-visit-link-state"]')->attr('data-state'));
        self::assertSelectorNotExists('[data-testid="encounter-visit-proposal"]');
        self::assertSelectorNotExists('[data-testid="encounter-visit-review-link"]');
        self::assertSame(
            ['ed_admission'],
            $crawler->filter('[data-testid="encounter-course-event"]')->each(static fn (Crawler $event): string => (string) $event->attr('data-event')),
            'Without effective link there is no IVENA time.',
        );
    }

    public function testReviewerSeesOpenProposalsWithLinksToTheReview(): void
    {
        $reviewer = $this->user();
        $this->encounterGrant($this->hospital, $reviewer, [EncounterGrantAction::Review]);
        $this->client->loginUser($reviewer);
        $crawler = $this->visit($this->pending);

        self::assertSame('pending', $crawler->filter('[data-testid="encounter-visit-link-state"]')->attr('data-state'));
        self::assertSelectorTextContains('[data-testid="encounter-visit-link"]', 'There are 2 open proposals for this visit.');
        self::assertCount(2, $crawler->filter('[data-testid="encounter-visit-proposal"]'));
        self::assertStringContainsString('Allocation #'.$this->firstProposal->getId(), $crawler->filter('[data-testid="encounter-visit-proposal"]')->first()->text(), 'Best score first.');
        self::assertSame(
            '/tools/encounter-review/'.$this->hospital->getId().'/allocations/'.$this->firstProposal->getId(),
            $crawler->filter('[data-testid="encounter-visit-review-link"]')->first()->attr('href'),
        );
    }

    public function testIncompleteVisitHidesItsEmptySections(): void
    {
        $this->client->loginUser($this->caseViewer());
        $crawler = $this->visit($this->minimal);

        self::assertSelectorTextContains('[data-testid="encounter-visit-title"]', 'ED visit of 12.10.2026, 13:31');
        self::assertSelectorExists('[data-testid="encounter-visit-anchor-assessment"]');
        self::assertSelectorExists('#overview');
        self::assertSelectorExists('#process-times', 'The first assessment is a documented time.');
        self::assertSelectorExists('[data-testid="encounter-visit-no-process-times"]');
        self::assertSame([], $this->intervals($crawler), 'No interval can be computed without ED admission.');
        self::assertSelectorNotExists('#triage');
        self::assertSelectorNotExists('#vitals');
        self::assertSelectorNotExists('#disposition');
        self::assertSelectorNotExists('#further-data');
        self::assertSame(
            ['triage' => '0', 'process-times' => '1', 'vitals' => '0', 'disposition' => '0', 'allocation' => '0'],
            $this->sectionAvailability($crawler),
        );
        self::assertSame('unlinked', $crawler->filter('[data-testid="encounter-visit-link-state"]')->attr('data-state'));
    }

    public function testCompleteVisitShowsEverySectionWithItsSource(): void
    {
        $this->client->loginUser($this->caseViewer());
        $crawler = $this->visit($this->linked);

        foreach (['overview', 'triage', 'process-times', 'vitals', 'disposition', 'allocation'] as $section) {
            self::assertSelectorExists('#'.$section);
        }
        self::assertSame(
            ['triage' => '1', 'process-times' => '1', 'vitals' => '1', 'disposition' => '1', 'allocation' => '1'],
            $this->sectionAvailability($crawler),
        );
        self::assertStringContainsString('from IVENA', $crawler->filter('#allocation .card-actions')->text(), 'The allocation names IVENA as its source.');
        self::assertStringContainsString('ED data', $crawler->filter('#triage .card-actions')->text());
    }

    public function testRecordsOfOtherSchemasAppearInTheirOwnSection(): void
    {
        $encounterId = $this->encounterEntityManager()->getConnection()->fetchOne('SELECT encounter_id FROM ed_visit WHERE visit_uid = ?', [$this->minimal]);
        self::assertIsInt($encounterId);
        $import = $this->encounterImport($this->hospital, 'Detail stays');
        $em = $this->encounterEntityManager();
        $em->persist(new InpatientStay(
            (int) $this->hospital->getId(),
            1,
            (int) $import->getId(),
            $encounterId,
            hash('sha256', 'detail|stay'),
            hash('sha256', 'detail|stay'),
            new \DateTimeImmutable('2026-10-12 15:00'),
            'I21.4',
            null,
        ));
        $em->flush();

        $this->client->loginUser($this->caseViewer());
        $crawler = $this->visit($this->minimal);

        self::assertSame('I21.4', trim($crawler->filter('#further-data [data-record-section="inpatient_stay"] [data-testid="inpatient-stay-diagnosis"]')->text()));
    }

    public function testLinkedAllocationShowsDepartmentTransportResourcesAndFeatures(): void
    {
        $this->allocationFlags(resus: true, cathlab: false, ventilated: true);

        $this->client->loginUser($this->caseViewer());
        $crawler = $this->visit($this->linked);

        self::assertSelectorExists('[data-testid="encounter-visit-allocation-department"]');
        self::assertSelectorExists('[data-testid="encounter-visit-allocation-transport"]');
        $resources = $crawler->filter('[data-testid="encounter-visit-allocation-resources"]')->text();
        self::assertStringContainsString('Resus', $resources);
        self::assertStringNotContainsString('Cath', $resources);
        self::assertSame(['Is Ventilated'], $this->featureLabels($crawler));
    }

    public function testThePageNeedsTheParticipantRoleThatAlsoOpensTheAllocation(): void
    {
        $withoutParticipant = $this->user(['ROLE_USER', 'ROLE_ENCOUNTER_BETA']);
        $this->encounterGrant($this->hospital, $withoutParticipant, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($withoutParticipant);
        $this->request($this->linked);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAccessNeedsViewCasesOnTheVisitsHospital(): void
    {
        $owner = $this->hospital->getOwner();
        self::assertInstanceOf(User::class, $owner);
        $this->encounterGrant($this->hospital, $owner, [EncounterGrantAction::Upload]);
        $this->client->loginUser($owner);
        $this->request($this->linked);
        self::assertResponseStatusCodeSame(403, 'UPLOAD alone does not open cases.');

        $otherHospitalViewer = $this->user();
        $this->encounterGrant($this->hospital($this->user()), $otherHospitalViewer, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($otherHospitalViewer);
        $this->request($this->linked);
        self::assertResponseStatusCodeSame(403, 'VIEW_CASES on another hospital.');

        $withoutBeta = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $this->encounterGrant($this->hospital, $withoutBeta, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($withoutBeta);
        $this->request($this->linked);
        self::assertResponseStatusCodeSame(403, 'The grant needs the beta role.');
    }

    public function testAdministratorWithoutGrantSeesTheVisitAndItsProposals(): void
    {
        $this->client->loginUser($this->admin());
        $crawler = $this->visit($this->pending);

        self::assertSame('pending', $crawler->filter('[data-testid="encounter-visit-link-state"]')->attr('data-state'));
    }

    public function testUnknownVisitIsNotFound(): void
    {
        $this->client->loginUser($this->caseViewer());
        $this->request('0192f3a4-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCaseCardAndUnlinkedVisitsLinkToTheDetailPage(): void
    {
        $reviewer = $this->user();
        $this->encounterGrant($this->hospital, $reviewer, [EncounterGrantAction::Review]);
        $this->hospitalGrant($this->hospital, $reviewer);
        $this->client->loginUser($reviewer);

        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->linkedAllocation->getPublicId(), server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertSame('/explore/ed-visits/visit/'.$this->linked, $crawler->filter('[data-testid="encounter-course-visit-link"]')->attr('href'));

        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-review/'.$this->hospital->getId().'/visits?from=2026-10-01&to=2026-10-31', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();
        $links = $crawler->filter('a[data-test="encounter-review-item"]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertContains('/explore/ed-visits/visit/'.$this->pending, $links);
        self::assertContains('/explore/ed-visits/visit/'.$this->minimal, $links);
    }

    public function testGermanPagesHaveNoMissingTranslations(): void
    {
        $reviewer = $this->user();
        $this->encounterGrant($this->hospital, $reviewer, [EncounterGrantAction::Review]);
        $this->client->loginUser($reviewer);

        foreach ([$this->linked, $this->pending, $this->minimal] as $uid) {
            $this->client->enableProfiler();
            $this->client->request(Request::METHOD_GET, '/explore/ed-visits/visit/'.$uid, server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);
            self::assertResponseIsSuccessful();
            $this->assertNoMissingTranslations($this->client->getProfile());
        }
        self::assertSelectorTextContains('[data-testid="encounter-visit-title"]', 'Notaufnahme-Besuch vom 12.10.2026, 13:31');
    }

    /**
     * @return list<string> minutes of the three process time KPIs, empty when missing
     */
    private function intervals(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="encounter-course-interval"]')->each(static fn (Crawler $value): string => (string) $value->attr('data-minutes'));
    }

    /**
     * @return array<string, string> section anchor => "1" when it can be jumped to, "0" when it is empty
     */
    private function sectionAvailability(Crawler $crawler): array
    {
        $sections = [];
        $crawler->filter('[data-testid="encounter-visit-sections"] [data-section]')->each(static function (Crawler $item) use (&$sections): void {
            $sections[(string) $item->attr('data-section')] = (string) $item->attr('data-available');
        });

        return $sections;
    }

    private function allocationFlags(bool $resus, bool $cathlab, bool $ventilated): void
    {
        $em = $this->encounterEntityManager();
        $em->getConnection()->executeStatement(
            'UPDATE allocation SET requires_resus = :resus, requires_cathlab = :cathlab, is_ventilated = :ventilated, is_cpr = false, is_shock = false, is_pregnant = false, is_work_accident = false WHERE id = :id',
            ['resus' => $resus, 'cathlab' => $cathlab, 'ventilated' => $ventilated, 'id' => $this->linkedAllocation->getId()],
            ['resus' => ParameterType::BOOLEAN, 'cathlab' => ParameterType::BOOLEAN, 'ventilated' => ParameterType::BOOLEAN, 'id' => ParameterType::INTEGER],
        );
        $em->clear();
    }

    /**
     * @return list<string>
     */
    private function featureLabels(Crawler $crawler): array
    {
        return $crawler->filter('[data-testid="encounter-visit-allocation-features"] .badge')->each(static fn (Crawler $badge): string => trim($badge->text()));
    }

    private function caseViewer(): User
    {
        $viewer = $this->user();
        $this->encounterGrant($this->hospital, $viewer, [EncounterGrantAction::ViewCases]);
        // The allocation case file in Explore needs access to the hospital as well (#1566).
        $this->hospitalGrant($this->hospital, $viewer);

        return $viewer;
    }

    private function request(string $uid): Crawler
    {
        return $this->client->request(Request::METHOD_GET, '/explore/ed-visits/visit/'.$uid, server: ['HTTP_Accept-Language' => 'en']);
    }

    private function visit(string $uid): Crawler
    {
        $crawler = $this->request($uid);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function uid(int $encounterId): string
    {
        $uid = $this->encounterEntityManager()->getConnection()->fetchOne('SELECT visit_uid FROM ed_visit WHERE encounter_id = ?', [$encounterId]);
        self::assertIsString($uid);

        return $uid;
    }

    private function pair(Allocation $allocation, int $encounterId, MatchStatus $status, float $score): void
    {
        $em = $this->encounterEntityManager();
        $encounter = $em->find(Encounter::class, $encounterId);
        $managed = $em->find(Allocation::class, $allocation->getId());
        self::assertInstanceOf(Encounter::class, $encounter);
        self::assertInstanceOf(Allocation::class, $managed);

        $em->persist(new EncounterMatch($managed, $encounter, $status, AnchorSource::Admission, 7, new \DateTimeImmutable('2026-10-12 11:00'), $score, 9, [], 1, '1.0.0'));
        $em->flush();
        EffectiveAssignments::syncFromMatches($em->getConnection());
    }
}
