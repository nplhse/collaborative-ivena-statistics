<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Plugin;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Event\EncountersImported;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayTestBundle;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Encounter\Poc\InpatientStayPoc;
use App\User\Domain\Entity\User;
use Cois\Plugin\EdVisit\Application\Message\MatchEncountersMessage;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * End-to-end proof of #1274 part 1 with the test-only schema bundle `inpatient_stay` (ADR 037), through the
 * normal entry points. Data and expected rows: InpatientStayPoc. Reviewer R (upload, review), case viewer V
 * (view cases), beta user N without grant.
 */
#[ResetDatabase]
final class InpatientStayImportTest extends WebTestCase
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

    private Allocation $allocationX;

    private Allocation $allocationY;

    private int $edImportId;

    /** @var array<string, int> */
    private array $visits;

    /** @var list<string> */
    private array $uploads = [];

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
        // The case file in Explore needs access to the hospital (#1566); the encounter grants decide the rest.
        foreach ([$this->reviewer, $this->viewer, $this->withoutGrant] as $user) {
            $this->hospitalGrant($this->hospital, $user);
        }
        $allocations = $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION);
        $this->allocationX = $this->allocationAt($this->hospital, $allocations, '08:30');
        $this->allocationY = $this->allocationAt($this->hospital, $allocations, '09:30');

        $this->edImportId = $this->uploadEncounterFile($this->hospital, $this->reviewer, InpatientStayPoc::edVisits(), 'ed visits');
        $this->visits = $this->visitIdsOfImport($this->edImportId, 'A', 'B', 'C');
        $this->linkVisit((int) $this->allocationX->getId(), $this->visits['A']);
        $this->linkVisit((int) $this->allocationY->getId(), $this->visits['B']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        $filesystem = new Filesystem();
        $filesystem->remove($this->uploads);
        foreach ($this->entityManager()->getRepository(Import::class)->findAll() as $import) {
            if (null !== $import->getFilePath() && str_starts_with($import->getFilePath(), 'var/imports/') && !str_contains($import->getFilePath(), 'test-encounter')) {
                $filesystem->remove($this->projectDir().'/'.$import->getFilePath());
            }
        }

        parent::tearDown();
    }

    public function testTheBundleIsRegisteredLikeAnInstalledBundle(): void
    {
        self::assertContains(InpatientStayTestBundle::class, self::getContainer()->getParameter('kernel.bundles'));
    }

    public function testUploadReportCaseFileConflictsExploreReprocessingAndDeletion(): void
    {
        // 1. Selection: R is offered inpatient_stay under its label; N is offered no encounter upload.
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');
        self::assertSame('Inpatient stays (test)', trim($crawler->filter('select[name="import_create[encounterSchema]"] option[value="inpatient_stay"]')->text()));
        $this->client->loginUser($this->withoutGrant);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');
        self::assertCount(0, $crawler->filter('select[name="import_create[type]"] option')->reduce(static fn ($option): bool => str_contains($option->text(), 'Encounter data')));

        // 2. Processing and report through the shared workflow.
        $stayImportId = $this->uploadStaysThroughTheForm();
        $this->client->request(Request::METHOD_GET, '/import/'.$stayImportId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-test="encounter-schema"]', 'Inpatient stays (test)');
        $this->assertReport($stayImportId, inserted: 3, duplicates: 1, assigned: 1, conflicts: 1, unassigned: 1, rejected: 0, skipped: 0);

        // 3. Case file: the stay of row 1 at allocation X, for R and V; nothing at allocation Y.
        foreach ([$this->reviewer, $this->viewer] as $user) {
            $this->client->loginUser($user);
            $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationX->getPublicId());
            self::assertResponseIsSuccessful();
            $stay = $crawler->filter('[data-testid="encounter-hospital-course"] [data-record-section="inpatient_stay"] [data-testid="inpatient-stay"]');
            self::assertCount(1, $stay);
            self::assertSame(['2026-10-12 10:05', 'I21.4', '2026-10-19 11:00'], [
                trim($stay->filter('[data-testid="inpatient-stay-admitted"]')->text()),
                trim($stay->filter('[data-testid="inpatient-stay-diagnosis"]')->text()),
                trim($stay->filter('[data-testid="inpatient-stay-discharged"]')->text()),
            ]);
        }
        $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationY->getPublicId());
        self::assertSelectorNotExists('[data-record-section="inpatient_stay"]');

        // 4. Conflict of row 3 with candidates B and C: visible for R, not for V.
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments/'.$this->hospital->getId());
        $conflict = $crawler->filter('[data-test="encounter-assignment-conflict"]');
        self::assertCount(1, $conflict);
        self::assertStringContainsString('Inpatient stays (test)', $conflict->text());
        self::assertCount(2, $conflict->filter('[data-test="encounter-assignment-candidate"] a'));
        $this->client->loginUser($this->viewer);
        $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments/'.$this->hospital->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // 5. Missing candidate: row 4 is kept unassigned and appears in no case file.
        self::assertSame(['no_candidate', 'ambiguous'], $this->connection()->fetchFirstColumn('SELECT kind FROM encounter_pending_assignment WHERE import_id = ? ORDER BY kind DESC', [$stayImportId]));
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM test_inpatient_stay WHERE import_id = ? AND encounter_id IS NOT NULL', [$stayImportId]));

        // 6. Explore: the tile and its page for R and V; for N no tile and 403 on the page.
        foreach ([$this->reviewer, $this->viewer] as $user) {
            $this->client->loginUser($user);
            $crawler = $this->client->request(Request::METHOD_GET, '/explore');
            self::assertCount(1, $crawler->filter('[data-testid="explore-tile-inpatient-stays"] a[href="/explore/inpatient-stays"]'));
            $this->client->request(Request::METHOD_GET, '/explore/inpatient-stays');
            self::assertSelectorExists('[data-testid="inpatient-stay-hospitals"] a[href="/explore/inpatient-stays/'.$this->hospital->getId().'"]');
            $crawler = $this->client->request(Request::METHOD_GET, '/explore/inpatient-stays/'.$this->hospital->getId());
            self::assertResponseIsSuccessful();
            self::assertCount(3, $crawler->filter('[data-testid="inpatient-stay-row"]'));
        }
        $this->client->loginUser($this->withoutGrant);
        $crawler = $this->client->request(Request::METHOD_GET, '/explore');
        self::assertCount(0, $crawler->filter('[data-testid="explore-tile-inpatient-stays"]'));
        $this->client->request(Request::METHOD_GET, '/explore/inpatient-stays/'.$this->hospital->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // 7. Reprocessing gives the same counts, one stay per new row and one conflict.
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/'.$stayImportId);
        $this->client->request(Request::METHOD_POST, '/import/'.$stayImportId.'/reprocess', ['_token' => $crawler->filter('#import-reprocess-modal input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/import/'.$stayImportId.'/processing');
        $this->client->request(Request::METHOD_GET, '/import/'.$stayImportId);
        $this->assertReport($stayImportId, inserted: 3, duplicates: 1, assigned: 1, conflicts: 1, unassigned: 1, rejected: 0, skipped: 0);
        self::assertSame(3, $this->stayCount($stayImportId));

        // 8. Deleting the ed_visit import (#1300): its visits and their ED matching history go; the encounters stay
        // with their IVENA assignment, so the stay of row 1 is still in the case file of allocation X.
        $crawler = $this->client->request(Request::METHOD_GET, '/import/'.$this->edImportId);
        $this->client->request(Request::METHOD_POST, '/import/'.$this->edImportId.'/delete', ['_token' => $crawler->filter('#import-delete-modal input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/import');
        self::assertNull($this->entityManager()->find(Import::class, $this->edImportId));
        self::assertSame(0, $this->encounterCount($this->edImportId));
        self::assertSame(3, $this->stayCount($stayImportId));
        self::assertCount(0, $this->links(), 'The ED matching history of the visits is gone.');
        self::assertSame($this->visits['A'], (int) $this->connection()->fetchOne('SELECT id FROM encounter WHERE allocation_id = ?', [$this->allocationX->getId()]));
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationX->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="encounter-course-link"]'), 'No ED visit any more.');
        self::assertSame('I21.4', trim($crawler->filter('[data-record-section="inpatient_stay"] [data-testid="inpatient-stay-diagnosis"]')->text()));

        // 8b. Without ED visit, a reviewer can remove the assignment by hand; a case viewer cannot.
        $this->client->loginUser($this->viewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationX->getPublicId());
        self::assertCount(0, $crawler->filter('[data-testid="encounter-course-unassign"]'));
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationX->getPublicId());
        $this->client->submit($crawler->filter('[data-testid="encounter-course-unassign"] form')->form());
        self::assertResponseRedirects('/explore/allocation/'.$this->allocationX->getPublicId());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'The assignment was removed.');
        self::assertCount(0, $crawler->filter('[data-record-section="inpatient_stay"]'));
        self::assertNull($this->connection()->fetchOne('SELECT allocation_id FROM encounter WHERE id = ?', [$this->visits['A']]));
        self::assertSame($this->visits['A'], (int) $this->connection()->fetchOne('SELECT encounter_id FROM test_inpatient_stay WHERE import_id = ? AND discharge_diagnosis = ?', [$stayImportId, 'I21.4']), 'The stay stays with its encounter.');

        // 9. Deleting the inpatient_stay import removes its stays and pending records, and the encounters left without
        // any record go with it (#1644): A, B and C had only stays after the ED import was deleted in step 8.
        $crawler = $this->client->request(Request::METHOD_GET, '/import/'.$stayImportId);
        $this->client->request(Request::METHOD_POST, '/import/'.$stayImportId.'/delete', ['_token' => $crawler->filter('#import-delete-modal input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects('/import');
        self::assertSame(0, $this->stayCount($stayImportId));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_pending_assignment WHERE import_id = ?', [$stayImportId]));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE id IN (?, ?, ?)', [$this->visits['A'], $this->visits['B'], $this->visits['C']]));
    }

    public function testStaysTriggerNoEncounterMatchingWhileVisitsStillDo(): void
    {
        /** @var \ArrayObject<int, EncountersImported> $events */
        $events = new \ArrayObject();
        /** @var \ArrayObject<int, MatchEncountersMessage> $messages */
        $messages = new \ArrayObject();
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(EncountersImported::class, static function (EncountersImported $event) use ($events): void {
            $events[] = $event;
        });
        $dispatcher->addListener(SendMessageToTransportsEvent::class, static function (SendMessageToTransportsEvent $event) use ($messages): void {
            if ($event->getEnvelope()->getMessage() instanceof MatchEncountersMessage) {
                $messages[] = $event->getEnvelope()->getMessage();
            }
        });

        $stayImportId = $this->uploadEncounterFile($this->hospital, $this->reviewer, InpatientStayPoc::stays(), 'stays', schema: InpatientStayDimension::SCHEMA);
        self::assertCount(0, $events, 'A stay adds no encounter, so no matching is announced.');
        self::assertCount(0, $messages);

        $this->deleteImport($stayImportId);
        self::assertCount(0, $messages, 'Deleting stays frees no hospital-day of matching.');

        $this->uploadEncounterFile($this->hospital, $this->reviewer, \App\Tests\Support\Encounter\EdVisitFileBuilder::create()->visit('D', '2026-10-13T08:15')->build(), 'more visits');
        self::assertCount(1, $events, 'New visits still announce their days.');
        self::assertCount(1, $messages);
    }

    public function testTheCheckedInCsvMatchesTheProofData(): void
    {
        self::assertStringEqualsFile($this->projectDir().'/'.InpatientStayPoc::CSV, InpatientStayPoc::stays());
    }

    private function uploadStaysThroughTheForm(): int
    {
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');
        $form = $crawler->filter('form[name="import_create"]')->form();
        $form['import_create[name]'] = 'inpatient stays';
        $form['import_create[hospital]'] = (string) $this->hospital->getId();
        $form['import_create[type]'] = $crawler->filter('select[name="import_create[type]"] option')
            ->reduce(static fn ($option): bool => str_contains($option->text(), 'Encounter data'))
            ->attr('value') ?? '';
        $form['import_create[encounterSchema]'] = InpatientStayDimension::SCHEMA;
        $confirmation = $form['import_create[encounterConfirmation]'];
        self::assertInstanceOf(ChoiceFormField::class, $confirmation);
        $confirmation->tick();
        // The first upload for a hospital confirms the authority to upload (#1565).
        $authority = $form['import_create[uploadAuthority]'];
        self::assertInstanceOf(ChoiceFormField::class, $authority);
        $authority->tick();
        $path = sys_get_temp_dir().'/inpatient-stays-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, InpatientStayPoc::stays());
        $this->uploads[] = $path;
        $file = $form['import_create[file]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($path);
        $this->client->submit($form);

        $import = $this->entityManager()->getRepository(Import::class)->findOneBy(['name' => 'inpatient stays']);
        self::assertInstanceOf(Import::class, $import);
        self::assertResponseRedirects('/import/'.$import->getId().'/processing');

        return (int) $import->getId();
    }

    private function assertReport(int $importId, int $inserted, int $duplicates, int $assigned, int $conflicts, int $unassigned, int $rejected, int $skipped): void
    {
        $count = fn (string $key): int => (int) trim($this->client->getCrawler()->filter('[data-test="count-'.$key.'"]')->text());
        // Duplicates and rejected rows are shown in the run card of the page only (#1413).
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);
        $this->entityManager()->refresh($import);

        self::assertSame(
            ['inserted' => $inserted, 'duplicates' => $duplicates, 'assigned' => $assigned, 'conflicts' => $conflicts, 'unassigned' => $unassigned, 'rejected' => $rejected, 'skipped' => $skipped],
            ['inserted' => $count('inserted'), 'duplicates' => (int) $import->getRowsDeduplicated(), 'assigned' => $count('assigned'), 'conflicts' => $count('conflicts'), 'unassigned' => $count('unassigned'), 'rejected' => (int) $import->getRowsRejected(), 'skipped' => $count('skipped')],
        );
    }

    private function stayCount(int $importId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM test_inpatient_stay WHERE import_id = ?', [$importId]);
    }

    private function deleteImport(int $importId): void
    {
        $service = self::getContainer()->get(\App\Import\Application\Service\ImportDeletionService::class);
        self::assertInstanceOf(\App\Import\Application\Service\ImportDeletionService::class, $service);
        $this->entityManager()->clear();
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $import);
        $service->delete($import);
    }
}
