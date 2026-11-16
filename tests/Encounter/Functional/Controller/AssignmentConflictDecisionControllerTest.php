<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Encounter\Poc\InpatientStayPoc;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Manual decisions on the conflict page (#1307) with the data of the #1274 proof: visits A (linked to X),
 * B (linked to Y) and C, where B and C share one case; stay row 3 has the case of B and C (conflict), row 4
 * no visit. Reviewer R (upload, review), case viewer V, beta user N without grant.
 */
#[ResetDatabase]
final class AssignmentConflictDecisionControllerTest extends WebTestCase
{
    use AttachedRecordFixtures;
    use EncounterDataFixtures;
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;

    private KernelBrowser $client;

    private Hospital $hospital;

    private User $reviewer;

    private User $viewer;

    private User $withoutGrant;

    private Allocation $allocationY;

    /** @var array<string, int> */
    private array $visits;

    private int $stayImportId;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->hospital = $this->hospital($this->user());
        $this->reviewer = $this->user();
        $this->viewer = $this->user();
        $this->withoutGrant = $this->user();
        $this->encounterGrant($this->hospital, $this->reviewer, [EncounterGrantAction::Upload, EncounterGrantAction::Review]);
        $this->encounterGrant($this->hospital, $this->viewer, [EncounterGrantAction::ViewCases]);
        $allocations = $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION);
        $allocationX = $this->allocationAt($this->hospital, $allocations, '08:30');
        $this->allocationY = $this->allocationAt($this->hospital, $allocations, '09:30');

        $edImportId = $this->uploadEncounterFile($this->hospital, $this->reviewer, InpatientStayPoc::edVisits(), 'ed visits');
        $this->visits = $this->visitIdsOfImport($edImportId, 'A', 'B', 'C');
        $this->linkVisit((int) $allocationX->getId(), $this->visits['A']);
        $this->linkVisit((int) $this->allocationY->getId(), $this->visits['B']);
        $this->stayImportId = $this->uploadEncounterFile($this->hospital, $this->reviewer, InpatientStayPoc::stays(), 'stays', schema: InpatientStayDimension::SCHEMA);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();

        parent::tearDown();
    }

    public function testAssigningToBShowsTheStayInTheCaseFileOfY(): void
    {
        $crawler = $this->conflictPage();
        $this->client->submit($this->assignForm($crawler, $this->visits['B']));

        $crawler = $this->followToConflictPage();
        self::assertSelectorTextContains('.alert-success', 'The record was assigned.');
        self::assertCount(0, $crawler->filter('[data-test="encounter-assignment-conflict"]'));
        self::assertSame(1, $this->decisionCount());

        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationY->getPublicId());
        self::assertResponseIsSuccessful();
        $stay = $crawler->filter('[data-testid="encounter-hospital-course"] [data-record-section="inpatient_stay"] [data-testid="inpatient-stay"]');
        self::assertCount(1, $stay);
        self::assertSame('J18.9', trim($stay->filter('[data-testid="inpatient-stay-diagnosis"]')->text()));
    }

    public function testLeavingUnassignedMovesTheRecordToTheDeferredSection(): void
    {
        $crawler = $this->conflictPage();
        self::assertCount(0, $crawler->filter('[data-test="encounter-assignment-deferred"]'));
        $this->client->submit($crawler->filter('[data-test="encounter-assignment-defer-form"]')->form());

        $crawler = $this->followToConflictPage();
        self::assertSelectorTextContains('.alert-success', 'The record was left unassigned for now.');
        self::assertCount(0, $crawler->filter('[data-test="encounter-assignment-conflict"]'));
        $deferred = $crawler->filter('[data-test="encounter-assignment-deferred-record"]');
        self::assertCount(1, $deferred);
        self::assertStringContainsString((string) $this->reviewer->getUsername(), $deferred->filter('[data-test="encounter-assignment-deferred-decision"]')->text());
        self::assertStringContainsString('Left unassigned for now (1)', $crawler->filter('[data-test="encounter-assignment-deferred-toggle"]')->text());
        self::assertNull($this->stayEncounterOfRow3());
    }

    public function testANewCandidateAfterLoadingThePageDecidesNothing(): void
    {
        $crawler = $this->conflictPage();
        $assign = $this->assignForm($crawler, $this->visits['B']);
        $defer = $crawler->filter('[data-test="encounter-assignment-defer-form"]')->form();

        $this->uploadEncounterFile($this->hospital, $this->reviewer, EdVisitFileBuilder::create()->visit('F', '2026-10-12T20:10', 'BC')->build(), 'more visits');

        foreach ([$assign, $defer] as $form) {
            $this->client->submit($form);
            $crawler = $this->followToConflictPage();
            self::assertSelectorTextContains('.alert-warning', 'The assignment has changed since the page was loaded.');
            $conflict = $crawler->filter('[data-test="encounter-assignment-conflict"]');
            self::assertCount(1, $conflict);
            self::assertCount(3, $conflict->filter('[data-test="encounter-assignment-candidate"]'), 'The page shows the fresh conflict with B, C and F.');
        }

        self::assertSame(0, $this->decisionCount());
        self::assertNull($this->stayEncounterOfRow3());
    }

    public function testOnlyReviewersOfTheHospitalCanDecide(): void
    {
        $crawler = $this->conflictPage();
        $form = $this->assignForm($crawler, $this->visits['B']);

        foreach ([$this->viewer, $this->withoutGrant] as $user) {
            $this->client->loginUser($user);
            $this->client->request(Request::METHOD_POST, $form->getUri(), $form->getValues());
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        }

        $other = $this->hospital($this->user());
        $this->client->loginUser($this->reviewer);
        $this->client->request(Request::METHOD_POST, str_replace('/encounter-assignments/'.$this->hospital->getId().'/', '/encounter-assignments/'.$other->getId().'/', $form->getUri()), $form->getValues());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'R has no review grant for the other hospital.');

        $values = $form->getValues();
        $values['_token'] = 'invalid';
        $this->client->request(Request::METHOD_POST, $form->getUri(), $values);
        $this->followToConflictPage();
        self::assertSelectorTextContains('.alert-danger', 'The form has expired.');

        self::assertSame(0, $this->decisionCount());
        self::assertNull($this->stayEncounterOfRow3());
    }

    public function testReprocessingRemovesDecisionsAndTheConflictReturns(): void
    {
        $crawler = $this->conflictPage();
        $this->client->submit($crawler->filter('[data-test="encounter-assignment-defer-form"]')->form());
        self::assertSame(1, $this->decisionCount());

        // Through the real reprocess path: the import row is kept, so the decisions go explicitly.
        $crawler = $this->client->request(Request::METHOD_GET, '/import/'.$this->stayImportId);
        $this->client->request(Request::METHOD_POST, '/import/'.$this->stayImportId.'/reprocess', ['_token' => $crawler->filter('#import-reprocess-modal input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/import/'.$this->stayImportId.'/processing');

        self::assertSame(0, $this->decisionCount());
        self::assertSame(['open'], $this->connection()->fetchFirstColumn("SELECT status FROM encounter_pending_assignment WHERE import_id = ? AND kind = 'ambiguous'", [$this->stayImportId]));
        $crawler = $this->conflictPage();
        self::assertCount(1, $crawler->filter('[data-test="encounter-assignment-conflict"]'));
        self::assertCount(0, $crawler->filter('[data-test="encounter-assignment-deferred"]'));
    }

    public function testDeletingTheImportRemovesItsDecisions(): void
    {
        $crawler = $this->conflictPage();
        $this->client->submit($crawler->filter('[data-test="encounter-assignment-defer-form"]')->form());
        self::assertSame(1, $this->decisionCount());

        $crawler = $this->client->request(Request::METHOD_GET, '/import/'.$this->stayImportId);
        $this->client->request(Request::METHOD_POST, '/import/'.$this->stayImportId.'/delete', ['_token' => $crawler->filter('#import-delete-modal input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/import');

        self::assertSame(0, $this->decisionCount());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_pending_assignment WHERE import_id = ?', [$this->stayImportId]));
    }

    public function testACandidateThatDisappearsAfterLoadingThePageIsAssignedByTheTargetedRetry(): void
    {
        $crawler = $this->conflictPage();
        $assign = $this->assignForm($crawler, $this->visits['C']);
        $this->connection()->executeStatement('UPDATE encounter SET case_key = ? WHERE id = ?', [EncounterFileBuilder::caseKey('other'), $this->visits['C']]);

        $this->client->submit($assign);

        $crawler = $this->followToConflictPage();
        self::assertSelectorTextContains('.alert-warning', 'The assignment has changed since the page was loaded.');
        self::assertSame(0, $this->decisionCount(), 'The old form decides nothing.');
        self::assertSame($this->visits['B'], $this->stayEncounterOfRow3(), 'The targeted retry attached the stay to B, its only candidate now.');
        self::assertCount(0, $crawler->filter('[data-test="encounter-assignment-conflict"]'), 'The conflict cannot stay open.');
    }

    public function testAnEdVisitImportReopensADeferredConflictAndAssignsALateCandidate(): void
    {
        $crawler = $this->conflictPage();
        $this->client->submit($crawler->filter('[data-test="encounter-assignment-defer-form"]')->form());

        // Visit F with the case of B and C, and visit D with the case of row 4, arrive in a later ED import.
        $this->uploadEncounterFile($this->hospital, $this->reviewer, EdVisitFileBuilder::create()
            ->visit('F', '2026-10-12T20:10', 'BC')
            ->visit('D', '2026-10-13T07:40', 'none')
            ->build(), 'more visits');

        $crawler = $this->conflictPage();
        $conflict = $crawler->filter('[data-test="encounter-assignment-conflict"]');
        self::assertCount(1, $conflict, 'Visit F reopens the deferred conflict.');
        self::assertCount(3, $conflict->filter('[data-test="encounter-assignment-candidate"]'));
        self::assertCount(0, $crawler->filter('[data-test="encounter-assignment-deferred"]'));

        $d = $this->visitIdsOfImport((int) $this->connection()->fetchOne("SELECT id FROM import WHERE name = 'more visits'"), 'D')['D'];
        self::assertSame($d, (int) $this->connection()->fetchOne('SELECT encounter_id FROM test_inpatient_stay WHERE import_id = ? AND discharge_diagnosis = ?', [$this->stayImportId, 'S72.0']), 'The stay of row 4 is attached to D without a new upload.');
    }

    private function conflictPage(): Crawler
    {
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments/'.$this->hospital->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function followToConflictPage(): Crawler
    {
        self::assertResponseRedirects('/tools/encounter-assignments/'.$this->hospital->getId());

        return $this->client->followRedirect();
    }

    private function assignForm(Crawler $crawler, int $encounterId): Form
    {
        $candidate = $crawler->filter('[data-test="encounter-assignment-candidate"][data-encounter="'.$encounterId.'"]');
        self::assertCount(1, $candidate);

        return $candidate->filter('[data-test="encounter-assignment-assign-form"]')->form();
    }

    private function stayEncounterOfRow3(): ?int
    {
        $id = $this->connection()->fetchOne('SELECT encounter_id FROM test_inpatient_stay WHERE import_id = ? AND discharge_diagnosis = ?', [$this->stayImportId, 'J18.9']);

        return null === $id ? null : (int) $id;
    }

    private function decisionCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_assignment_decision');
    }
}
