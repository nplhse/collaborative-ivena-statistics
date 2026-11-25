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
use App\Tests\Support\Encounter\Probe\ProbeDimension;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Where a schema appears (ADR 037): the tile of the test bundle `inpatient_stay` on the Explore overview, its
 * record section in the hospital course card of the case file, and assignment conflicts (of the probe
 * schema) on the read-only conflict page.
 *
 * Hospital H: allocation X linked to visit a (case `A`), allocation Y linked to visit b; visits y and z share
 * case 5, so a probe row of case 5 is an ambiguous conflict. Reviewer R (review grant), case
 * viewer V (view-cases grant), beta user N without grant.
 */
#[ResetDatabase]
final class SchemaContributionsControllerTest extends WebTestCase
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

    /** @var array<string, int> */
    private array $visits;

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

        $edImportId = $this->uploadEncounterFile($this->hospital, $this->reviewer, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-12T08:15', 'A')
            ->visit('b', '2026-10-12T09:15')
            ->visit('y', '2026-10-12T10:15', '5')
            ->visit('z', '2026-10-12T11:15', '5')
            ->build(), 'ed visits');
        $this->visits = $this->visitIdsOfImport($edImportId, 'a', 'b', 'y', 'z');
        $this->linkVisit((int) $this->allocationX->getId(), $this->visits['a']);
        $this->linkVisit((int) $this->allocationY->getId(), $this->visits['b']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        parent::tearDown();
    }

    public function testExploreShowsTheSchemaTileToUsersWhoMayViewCasesSomewhere(): void
    {
        foreach ([$this->viewer, $this->reviewer, $this->admin()] as $user) {
            $crawler = $this->explore($user);
            self::assertCount(1, $crawler->filter('[data-testid="explore-group-cases"] [data-testid="explore-tile-inpatient-stays"]'));
            // The ED visit list comes first, then the tiles of schema bundles.
            self::assertSame(
                ['explore-tile-ed-visits', 'explore-tile-inpatient-stays'],
                $crawler->filter('[data-testid="explore-group-cases"] [data-testid^="explore-tile-"]')->each(static fn ($tile): string => (string) $tile->attr('data-testid')),
            );
        }

        $crawler = $this->explore($this->withoutGrant);
        self::assertCount(1, $crawler->filter('[data-testid="explore-tile-ed-visits"]'), 'The ED visit list keeps its rule: the beta role.');
        self::assertCount(0, $crawler->filter('[data-testid="explore-tile-inpatient-stays"]'));

        $crawler = $this->explore($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        self::assertCount(0, $crawler->filter('[data-testid^="explore-tile-"]'));
    }

    public function testCaseFileShowsTheRecordSectionOfTheLinkedEncounter(): void
    {
        // A stay of the test bundle with the case of visit a, which is linked to allocation X.
        $this->uploadEncounterFile($this->hospital, $this->reviewer, EncounterFileBuilder::for(InpatientStayDimension::SCHEMA, InpatientStayDimension::header(), InpatientStayDimension::FINGERPRINT_RULE)
            ->row(['stay_fingerprint' => hash('sha256', 'stay|a'), 'case_key' => EncounterFileBuilder::caseKey('A'), 'admitted_at' => '2026-10-12T10:05', 'discharge_diagnosis' => 'I21.4', 'discharged_at' => ''])
            ->build(), 'stays', schema: InpatientStayDimension::SCHEMA);
        $this->client->loginUser($this->viewer);

        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationX->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSame(
            '2026-10-12 10:05',
            trim($crawler->filter('[data-testid="encounter-hospital-course"] [data-record-section="inpatient_stay"] [data-testid="inpatient-stay-admitted"]')->text()),
        );

        $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationY->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="encounter-hospital-course"]');
        self::assertSelectorNotExists('[data-record-section]');
    }

    public function testReviewerSeesTheConflictWithItsCandidates(): void
    {
        $this->uploadConflict();
        $this->client->loginUser($this->reviewer);

        $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments');
        self::assertResponseRedirects('/tools/encounter-assignments/'.$this->hospital->getId(), null, 'One hospital opens directly.');
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-test="encounter-assignment-conflict"]');
        self::assertCount(1, $row);
        self::assertSame(hash('sha256', 'probe|d'), $row->attr('data-record'));
        self::assertStringContainsString('Probe records (test)', $row->text());
        self::assertSame('Several encounters with the same case number', trim($row->filter('[data-test="encounter-assignment-kind"]')->text()));
        self::assertCount(2, $row->filter('[data-test="encounter-assignment-candidate"] a[href^="/explore/ed-visits/visit/"]'));
    }

    public function testReviewerWithoutConflictsSeesAnEmptyList(): void
    {
        $this->client->loginUser($this->reviewer);
        $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments/'.$this->hospital->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="encounter-assignment-no-conflicts"]');
        self::assertSelectorNotExists('[data-test="encounter-assignment-conflict"]');
    }

    public function testCaseViewerCannotOpenTheConflicts(): void
    {
        $this->uploadConflict();
        $this->client->loginUser($this->viewer);

        $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="encounter-assignment-no-hospitals"]');

        $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments/'.$this->hospital->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdministratorSeesEveryHospitalWithConflicts(): void
    {
        $this->uploadConflict();
        $this->client->loginUser($this->admin());

        $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments');
        self::assertResponseRedirects('/tools/encounter-assignments/'.$this->hospital->getId());
    }

    private function uploadConflict(): void
    {
        $this->uploadEncounterFile($this->hospital, $this->reviewer, EncounterFileBuilder::for(ProbeDimension::SCHEMA, ProbeDimension::header(), ProbeDimension::FINGERPRINT_RULE)
            ->row(['record_fingerprint' => hash('sha256', 'probe|d'), 'case_key' => EncounterFileBuilder::caseKey('5'), 'recorded_at' => '2026-10-12T12:00'])
            ->build(), 'probe records', schema: ProbeDimension::SCHEMA);
    }

    private function explore(User $user): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request(Request::METHOD_GET, '/explore', server: ['HTTP_Accept-Language' => 'en']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
