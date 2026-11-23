<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Tests\Functional\Controller;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\UI\Http\Controller\Assignment\UnassignEncounterController;
use App\Import\Domain\Enum\ImportType;
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
use Cois\Plugin\EdVisit\Domain\Enum\MatchStatus;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCategory;
use Cois\Plugin\EdVisit\Domain\Enum\VitalsReference;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Card "Hospital course" on the allocation case file (#1055): only with VIEW_CASES on the allocation's
 * hospital (administrators implicitly). Allocation "linked" arrives at 10:00 on 12 October 2026 and is
 * linked automatically (score 0.9697) to a visit admitted at 10:07, first assessment 10:12, first
 * physician 10:40, ED discharge 13:07, with documented first vitals. Allocation "open" only has a
 * proposal.
 */
#[ResetDatabase]
final class HospitalCourseCardTest extends WebTestCase
{
    use AssertsNoMissingTranslations;
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private const string CARD = '[data-testid="encounter-hospital-course"]';

    private KernelBrowser $client;

    private Hospital $hospital;

    private Allocation $linked;

    private Allocation $open;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->hospital = $this->hospital($this->user());

        $visits = $this->encounterImport($this->hospital, 'Card visits');
        $allocations = $this->encounterImport($this->hospital, 'Card allocations', ImportType::ALLOCATION);
        $this->linked = $this->allocationAt($this->hospital, $allocations, '10:00');
        $this->open = $this->allocationAt($this->hospital, $allocations, '14:00');

        $linkedVisit = $this->encounterRepository()->insertIfNewReturningId(new EdVisit(
            hospital: $this->hospital,
            import: $visits,
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'card|linked'),
            caseKey: hash('sha256', 'card|linked'),
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
            vitals: new InitialVitals(VitalsReference::DocumentedInitial, bpSystolic: 145, bpDiastolic: 90, heartRate: 104, temperature: '37.8'),
        ));
        $openVisit = $this->visitAt($this->hospital, $visits, '14:20');

        $this->pair($this->linked, (int) $linkedVisit, MatchStatus::AutoAccepted);
        $this->pair($this->open, $openVisit, MatchStatus::Proposed);
    }

    public function testCaseViewerSeesTheLinkedVisit(): void
    {
        $this->client->loginUser($this->caseViewer());
        $crawler = $this->caseFile($this->linked);

        self::assertSelectorExists(self::CARD);
        self::assertSame('automatic', $crawler->filter('[data-testid="encounter-course-origin"]')->attr('data-origin'));
        self::assertSelectorTextContains('[data-testid="encounter-course-agreement"]', 'Agreement 96 %');
        self::assertStringContainsString('bg-green-lt', (string) $crawler->filter('[data-testid="encounter-course-agreement"]')->attr('class'), '0.9697 reaches the auto-accept threshold.');
        self::assertSelectorTextContains('[data-testid="encounter-course-link"]', 'min from expected IVENA arrival');
        self::assertSelectorTextContains('[data-testid="encounter-course-category"]', 'Very urgent');
        self::assertSelectorTextContains('[data-testid="encounter-course-triage"]', 'Chest pain');
        self::assertSame(
            ['ivena_arrival', 'ed_admission', 'first_assessment', 'first_physician', 'ed_discharge'],
            $crawler->filter('[data-testid="encounter-course-event"]')->each(static fn (Crawler $event): string => (string) $event->attr('data-event')),
            'Documented first vitals are not placed on the timeline.',
        );
        $arrival = $crawler->filter('[data-event="ivena_arrival"]');
        self::assertStringContainsString('Expected IVENA arrival', $arrival->text());
        self::assertSame('from IVENA', trim($arrival->filter('[data-testid="encounter-course-event-source"]')->text()));
        self::assertSame(
            ['5 min', '33 min', '3 h 0 min'],
            $crawler->filter('[data-testid="encounter-course-interval"] .h3')->each(static fn (Crawler $value): string => trim($value->text())),
            'Admission 10:07 to 10:12, 10:40 and 13:07.',
        );
        self::assertSame(
            [true, true, true],
            $crawler->filter('[data-testid="encounter-course-interval"] .subheader')->each(static fn (Crawler $label): bool => str_contains($label->html(), '<svg')),
            'Every process time has its icon.',
        );
        self::assertSame('documented_initial', $crawler->filter('[data-testid="encounter-course-vitals-reference"]')->attr('data-reference'));
        self::assertSelectorTextContains('[data-testid="encounter-course-vitals-reference"]', 'Documented first value');
        self::assertSelectorTextContains('[data-vital="blood_pressure"]', '145/90 mmHg');
        self::assertSame(
            ['blood_pressure', 'heart_rate', 'temperature'],
            $crawler->filter('[data-testid="encounter-course-vitals"] [data-vital]')->each(static fn (Crawler $vital): string => (string) $vital->attr('data-vital')),
            'The excerpt lists only documented values.',
        );
        self::assertSelectorTextContains('[data-testid="encounter-course-disposition"]', 'Admitted to this hospital');
        self::assertSelectorTextContains('[data-testid="encounter-course-excerpt"]', 'Monitoring / intermediate care');
    }

    public function testAnAssignmentTheEdVisitDecidesCannotBeRemovedByHand(): void
    {
        // The core asks the course providers (#1375): while the ED visit returns a course, its review decides.
        $reviewer = $this->user();
        $this->encounterGrant($this->hospital, $reviewer, [EncounterGrantAction::Review, EncounterGrantAction::ViewCases]);
        $allocationId = (int) $this->linked->getId();
        $encounterId = $this->entityManager()->getConnection()->fetchOne('SELECT id FROM encounter WHERE allocation_id = ?', [$allocationId]);
        self::assertNotFalse($encounterId, 'The linked allocation is assigned to the visit\'s encounter.');

        $this->client->disableReboot();
        $this->client->loginUser($reviewer);
        $this->caseFile($this->linked);
        self::assertSelectorNotExists('[data-testid="encounter-course-unassign"]');

        $this->client->request(Request::METHOD_POST, '/explore/allocation/'.$this->linked->getPublicId().'/encounter/unassign', [
            '_token' => $this->csrfToken(UnassignEncounterController::tokenId($allocationId)),
        ]);

        self::assertResponseRedirects('/explore/allocation/'.$this->linked->getPublicId());
        self::assertSame((int) $encounterId, (int) $this->entityManager()->getConnection()->fetchOne('SELECT id FROM encounter WHERE allocation_id = ?', [$allocationId]));
    }

    public function testAllocationWithOnlyAProposalShowsNoVisit(): void
    {
        $this->client->loginUser($this->caseViewer());
        $this->caseFile($this->open);

        self::assertSelectorExists('[data-testid="encounter-hospital-course-none"]');
        self::assertSelectorNotExists('[data-testid="encounter-course-origin"]');
    }

    public function testNoCardWithoutViewCasesOnTheAllocationsHospital(): void
    {
        $aggregatesOnly = $this->hospital->getOwner();
        self::assertInstanceOf(User::class, $aggregatesOnly);
        $this->encounterGrant($this->hospital, $aggregatesOnly, [EncounterGrantAction::Upload]);
        $this->client->loginUser($aggregatesOnly);
        $this->caseFile($this->linked);
        self::assertSelectorNotExists(self::CARD, 'Owner with UPLOAD sees aggregates, not cases.');

        $otherHospitalViewer = $this->user();
        $this->encounterGrant($this->hospital($this->user()), $otherHospitalViewer, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($otherHospitalViewer);
        $this->caseFile($this->linked);
        self::assertSelectorNotExists(self::CARD, 'VIEW_CASES on another hospital.');

        $withoutBeta = $this->user(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $this->encounterGrant($this->hospital, $withoutBeta, [EncounterGrantAction::ViewCases]);
        $this->client->loginUser($withoutBeta);
        $this->caseFile($this->linked);
        self::assertSelectorNotExists(self::CARD, 'The grant needs the beta role.');
    }

    public function testReviewerAndAdministratorSeeTheCard(): void
    {
        $reviewer = $this->user();
        $this->encounterGrant($this->hospital, $reviewer, [EncounterGrantAction::Review]);
        $this->client->loginUser($reviewer);
        $this->caseFile($this->linked);
        self::assertSelectorExists('[data-testid="encounter-course-origin"]', 'REVIEW implies VIEW_CASES.');

        $this->client->loginUser($this->admin());
        $this->caseFile($this->linked);
        self::assertSelectorExists('[data-testid="encounter-course-origin"]');
    }

    public function testGermanCardHasNoMissingTranslations(): void
    {
        $this->client->loginUser($this->caseViewer());
        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->linked->getPublicId(), server: ['HTTP_Accept-Language' => 'de-DE,de;q=0.9']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains(self::CARD, 'Behandlungsverlauf');
        self::assertSelectorTextContains('[data-testid="encounter-course-vitals-reference"]', 'Dokumentierter Erstwert');
        $this->assertNoMissingTranslations($this->client->getProfile());
    }

    private function caseViewer(): User
    {
        $viewer = $this->user();
        $this->encounterGrant($this->hospital, $viewer, [EncounterGrantAction::ViewCases]);

        return $viewer;
    }

    private function csrfToken(string $tokenId): string
    {
        $requestStack = $this->client->getContainer()->get('request_stack');
        $request = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = $this->client->getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function caseFile(Allocation $allocation): Crawler
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$allocation->getPublicId(), server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();

        return $crawler;
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
}
