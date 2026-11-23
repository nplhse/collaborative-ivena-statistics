<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\WithoutEdVisit;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayTestBundle;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\EncounterFileBuilder;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\Tests\Support\Encounter\Poc\InpatientStayPoc;
use App\Tests\Support\Kernel\KernelWithoutEdVisit;
use App\User\Domain\Entity\User;
use Cois\Plugin\EdVisit\EdVisitBundle;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The application with the ED visit plugin disabled (#1300 step 5): only the schemas of the test bundle
 * (`inpatient_stay`, `probe`) are registered. Encounters A, B and C of InpatientStayPoc exist without ED visits (as after disabling the
 * plugin); A is assigned to allocation X.
 */
#[ResetDatabase]
final class EdVisitBundleDisabledTest extends WebTestCase
{
    use EncounterDataFixtures;
    use EncounterTestFixtures;
    use Factories;
    use MatchingDataFixtures;
    use NoMissingRouteLinks;

    private KernelBrowser $client;

    private Hospital $hospital;

    private User $reviewer;

    private Allocation $allocationX;

    /** @var list<string> */
    private array $uploads = [];

    #[\Override]
    protected static function getKernelClass(): string
    {
        return KernelWithoutEdVisit::class;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->reviewer = $this->user();
        $this->hospital = $this->hospital($this->reviewer);
        $this->encounterGrant($this->hospital, $this->reviewer, [EncounterGrantAction::Upload, EncounterGrantAction::Review, EncounterGrantAction::ViewCases]);
        $this->allocationX = $this->allocationAt($this->hospital, $this->encounterImport($this->hospital, 'allocations', ImportType::ALLOCATION), '08:30');
        $this->encounter('A', '08:15', (int) $this->allocationX->getId());
        $this->encounter('BC', '09:15');
        $this->encounter('BC', '15:40');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->uploads);
        foreach ($this->entityManager()->getRepository(Import::class)->findAll() as $import) {
            if (null !== $import->getFilePath() && str_starts_with($import->getFilePath(), 'var/imports/')) {
                $filesystem->remove(self::getContainer()->getParameter('kernel.project_dir').'/'.$import->getFilePath());
            }
        }

        parent::tearDown();
    }

    public function testOnlyThePluginIsLeftOut(): void
    {
        $bundles = self::getContainer()->getParameter('kernel.bundles');
        self::assertIsArray($bundles);
        self::assertNotContains(EdVisitBundle::class, $bundles);
        self::assertContains(InpatientStayTestBundle::class, $bundles);
    }

    public function testTheEncounterImportOffersTheRemainingSchemaAndAcceptsItsFile(): void
    {
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();
        // The schemas of the test bundle, without `ed_visit`.
        self::assertSame([InpatientStayDimension::SCHEMA, 'probe'], $crawler->filter('select[name="import_create[encounterSchema]"] option')->each(static fn ($option): string => (string) $option->attr('value')));

        $import = $this->uploadStays();
        self::assertSame(ImportStatus::COMPLETED, $import->getStatus());
        $this->client->request(Request::METHOD_GET, '/import/'.$import->getId());
        self::assertResponseIsSuccessful();
        $counts = [];
        foreach (['inserted', 'assigned', 'conflicts', 'unassigned'] as $key) {
            $counts[$key] = (int) trim($this->client->getCrawler()->filter('[data-test="count-'.$key.'"]')->text());
        }
        self::assertSame(['inserted' => 3, 'assigned' => 1, 'conflicts' => 1, 'unassigned' => 1], $counts);
        // The known stay is counted in the run card of the page (#1413).
        self::assertSame(1, $import->getRowsDeduplicated());

        // The stay of case A is in the case file of allocation X.
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationX->getPublicId());
        self::assertResponseIsSuccessful();
        self::assertSame('I21.4', trim($crawler->filter('[data-record-section="inpatient_stay"] [data-testid="inpatient-stay-diagnosis"]')->text()));

        // The conflict of case BC lists both encounters with their anchor and can be decided; no schema offers a
        // record page for them, so they carry no link.
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/encounter-assignments/'.$this->hospital->getId());
        self::assertResponseIsSuccessful();
        $candidates = $crawler->filter('[data-test="encounter-assignment-conflict"] [data-test="encounter-assignment-candidate"]');
        self::assertCount(2, $candidates);
        self::assertCount(0, $candidates->filter('a'));
        self::assertStringContainsString('9:15', $candidates->first()->text());
        self::assertCount(2, $candidates->filter('[data-test="encounter-assignment-assign-form"]'));
    }

    public function testTheDataPrepToolShowsTheRemainingSchemaWithoutTheMatchReview(): void
    {
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/tools/data-prep');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/import/new"]'));
        self::assertCount(0, $crawler->filter('a[href="/tools/encounter-review"]'));
    }

    public function testTheCoreEntriesOnExploreAndToolsStay(): void
    {
        $this->client->loginUser($this->reviewer);

        // The tile of the remaining schema, forwarded by the core; no ED visit tile.
        $crawler = $this->client->request(Request::METHOD_GET, '/explore');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="explore-tile-inpatient-stays"] a[href="/explore/inpatient-stays"]'));
        self::assertCount(0, $crawler->filter('[data-testid="explore-tile-ed-visits"]'));

        // The core tools; none of the plugin.
        $crawler = $this->client->request(Request::METHOD_GET, '/tools');
        self::assertResponseIsSuccessful();
        $keys = $crawler->filter('[data-testid="tools-card"]')->each(static fn (Crawler $card): string => (string) $card->attr('data-tool-key'));
        self::assertContains('encounter_assignments', $keys);
        self::assertContains('encounter_data_prep', $keys);
        self::assertSame([], array_values(array_intersect($keys, ['encounter_review', 'encounter_validation', 'encounter_plausibility'])));
    }

    public function testTheDataCatalogShowsTheCoreObjectsWithoutTheEdVisit(): void
    {
        $this->client->loginUser($this->reviewer);

        // The encounter base data stays (#1415); the ED visit object belongs to the plugin.
        $crawler = $this->client->request(Request::METHOD_GET, '/help/data-catalog');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="data-catalog-object"][data-object-key="encounter"]'));
        self::assertCount(0, $crawler->filter('[data-object-key="ed_visit"]'));

        $this->client->request(Request::METHOD_GET, '/help/data-catalog/ed_visit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testGrantAndKeyPagesWork(): void
    {
        $owner = $this->user();
        $hospital = $this->hospital($owner);
        $this->client->loginUser($owner);

        foreach (['encounter-access', 'encounter-access/new', 'encounter-key'] as $page) {
            $this->client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/'.$page);
            self::assertResponseIsSuccessful($page);
        }
    }

    public function testTheKeyChangePageCountsTheEncountersOfTheCore(): void
    {
        // Encounters come from the core table, links and decisions only from schemas that have them (#1375).
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/hospitals/'.$this->hospital->getId().'/edit/encounter-key');
        self::assertResponseIsSuccessful();

        $message = (string) $crawler->filter('[data-encounter-key-change-delete-message-value]')->attr('data-encounter-key-change-delete-message-value');
        self::assertStringContainsString('3 encounters', $message);
        self::assertStringNotContainsString('link', $message);
    }

    public function testAReviewerRemovesAnAssignmentByHand(): void
    {
        // No schema returns a course for the encounter, so the case file offers the removal (#1375).
        $this->client->loginUser($this->reviewer);
        $crawler = $this->client->request(Request::METHOD_GET, '/explore/allocation/'.$this->allocationX->getPublicId());
        self::assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('[data-testid="encounter-course-unassign"] form')->form());

        self::assertResponseRedirects('/explore/allocation/'.$this->allocationX->getPublicId());
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM encounter WHERE allocation_id = ?', [$this->allocationX->getId()]));
    }

    public function testTheAnalyticsPageOfThePluginIsNotFound(): void
    {
        // No core rule names the plugin page any more (#1375): without the plugin it is a 404 for everyone.
        $this->client->loginUser($this->user(['ROLE_USER', 'ROLE_PARTICIPANT']));
        $this->client->request(Request::METHOD_GET, '/statistics/encounter-analytics');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNoMenuEntryPointsToAMissingRoute(): void
    {
        $admin = $this->user(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA', 'ROLE_ENCOUNTER_CATALOG_REVIEWER']);
        $this->hospital($admin);
        $this->client->loginUser($admin);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('MTS', $crawler->filter('#main-menu')->text());

        $this->assertNoLinkToAMissingRoute($this->client, ['/', '/tools', '/explore', '/hospitals', '/admin', '/tools/data-prep', '/import/new']);
    }

    private function encounter(string $case, string $anchor, ?int $allocationId = null): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO encounter (hospital_id, key_generation, case_key, anchor_at, age, sex, created_at, allocation_id) VALUES (?, 1, ?, ?, 70, 'F', '2026-10-12 20:00:00', ?)",
            [$this->hospital->getId(), EncounterFileBuilder::caseKey($case), '2026-10-12 '.$anchor.':00', $allocationId],
        );
    }

    private function uploadStays(): Import
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');
        $form = $crawler->filter('form[name="import_create"]')->form();
        $form['import_create[name]'] = 'inpatient stays';
        $form['import_create[hospital]'] = (string) $this->hospital->getId();
        $form['import_create[type]'] = $this->typeOption($crawler, 'Encounter data');
        $form['import_create[encounterSchema]'] = InpatientStayDimension::SCHEMA;
        $confirmation = $form['import_create[encounterConfirmation]'];
        self::assertInstanceOf(ChoiceFormField::class, $confirmation);
        $confirmation->tick();
        $file = $form['import_create[file]'];
        self::assertInstanceOf(FileFormField::class, $file);
        $file->upload($this->tempFile(InpatientStayPoc::stays()));
        $this->client->submit($form);

        return $this->importNamed('inpatient stays');
    }

    private function typeOption(Crawler $crawler, string $label): string
    {
        return (string) $crawler->filter('select[name="import_create[type]"] option')
            ->reduce(static fn (Crawler $option): bool => str_contains($option->text(), $label))
            ->attr('value');
    }

    private function importNamed(string $name): Import
    {
        $this->entityManager()->clear();
        $import = $this->entityManager()->getRepository(Import::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Import::class, $import);
        self::assertResponseRedirects('/import/'.$import->getId().'/processing');

        return $import;
    }

    private function tempFile(string $content): string
    {
        $path = sys_get_temp_dir().'/without-ed-visit-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $content);
        $this->uploads[] = $path;

        return $path;
    }

    private function connection(): Connection
    {
        return $this->encounterEntityManager()->getConnection();
    }
}
