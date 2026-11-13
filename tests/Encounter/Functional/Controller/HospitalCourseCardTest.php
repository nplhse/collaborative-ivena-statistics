<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterDisposition;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Entity\InitialVitals;
use App\Encounter\Domain\Entity\Triage;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\MtsCategory;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Domain\Enum\VitalsReference;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Translation\AssertsNoMissingTranslations;
use App\User\Domain\Entity\User;
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

        $linkedVisit = $this->encounterRepository()->insertIfNewReturningId(new Encounter(
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
        self::assertSelectorTextContains('[data-testid="encounter-course-category"]', 'Very urgent');
        self::assertSelectorTextContains('[data-testid="encounter-course-triage"]', 'Chest pain');
        self::assertSame(
            ['ivena_arrival', 'ed_admission', 'first_assessment', 'first_physician', 'ed_discharge'],
            $crawler->filter('[data-testid="encounter-course-event"]')->each(static fn (Crawler $event): string => (string) $event->attr('data-event')),
            'Documented first vitals are not placed on the timeline.',
        );
        self::assertSame(
            ['5 min', '33 min', '180 min'],
            $crawler->filter('[data-testid="encounter-course-interval"]')->each(static fn (Crawler $value): string => trim($value->text())),
        );
        self::assertSame('documented_initial', $crawler->filter('[data-testid="encounter-course-vitals-reference"]')->attr('data-reference'));
        self::assertSelectorTextContains('[data-testid="encounter-course-vitals-reference"]', 'Documented first value');
        self::assertSelectorTextContains('[data-vital="blood_pressure"]', '145/90 mmHg');
        self::assertSelectorTextContains('[data-testid="encounter-course-disposition"]', 'Monitoring / intermediate care');
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
    }
}
