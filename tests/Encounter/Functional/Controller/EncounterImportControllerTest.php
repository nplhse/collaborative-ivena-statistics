<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\EncounterImport;
use App\Encounter\Domain\Enum\EncounterGrantAction;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EdVisitFileBuilder;
use App\Tests\Support\Encounter\EncounterImportRunner;
use App\Tests\Support\Encounter\EncounterTestFixtures;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Encounter upload, report and reprocess pages (#1048, ADR 027).
 */
#[ResetDatabase]
final class EncounterImportControllerTest extends WebTestCase
{
    use EncounterImportRunner;
    use EncounterTestFixtures;
    use Factories;

    private KernelBrowser $client;

    private Hospital $hospital;

    private User $uploader;

    /** @var list<string> */
    private array $uploads = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->hospital = $this->hospital($this->user());
        $this->uploader = $this->user();
        $this->encounterGrant($this->hospital, $this->uploader, [EncounterGrantAction::Upload]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeEncounterFiles();
        $filesystem = new Filesystem();
        foreach ($this->uploads as $path) {
            $filesystem->remove($path);
        }

        foreach ($this->entityManager()->getRepository(Import::class)->findAll() as $import) {
            $filesystem->remove($this->projectDir().'/'.$import->getFilePath());
        }

        parent::tearDown();
    }

    public function testUploaderUploadsAFileWithConfirmation(): void
    {
        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/encounter/imports/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[data-test="encounter-tool-link"][href="/encounter"]');

        $form = $crawler->filter('form[name="encounter_import"]')->form();
        $form['encounter_import[hospital]'] = (string) $this->hospital->getId();
        $form['encounter_import[name]'] = 'October visits';
        $form['encounter_import[schema]'] = 'ed_visit';
        $this->checkbox($form, 'encounter_import[confirmation]')->tick();
        $this->fileField($form, 'encounter_import[file]')->upload($this->file(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->visit('b', '2026-10-01T09:15')->build()));
        $this->client->submit($form);

        $import = $this->entityManager()->getRepository(Import::class)->findOneBy(['name' => 'October visits']);
        self::assertInstanceOf(Import::class, $import);
        self::assertResponseRedirects('/encounter/imports/'.$import->getId());
        self::assertSame(ImportType::ENCOUNTER, $import->getType());
        self::assertSame(ImportStatus::COMPLETED, $import->getStatus());

        $encounterImport = $this->encounterImportOf((int) $import->getId());
        self::assertSame($this->uploader->getId(), $encounterImport->getConfirmedBy()?->getId());
        self::assertSame(1, $encounterImport->getKeyGeneration());
        self::assertSame(2, $encounterImport->getInserted());

        $audit = $this->connection()->fetchOne(
            "SELECT metadata FROM audit_log WHERE entity_class = ? AND action = 'create'",
            [EncounterImport::class],
        );
        self::assertIsString($audit, 'The confirmation is audited.');
        self::assertStringContainsString('encounter.import.created', $audit);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="count-inserted"]', '2');
        self::assertSelectorExists('[data-test="encounter-reprocess-warning"]');
    }

    public function testUploadWithoutConfirmationIsRefused(): void
    {
        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/encounter/imports/new');
        $form = $crawler->filter('form[name="encounter_import"]')->form();
        $form['encounter_import[hospital]'] = (string) $this->hospital->getId();
        $form['encounter_import[name]'] = 'Unconfirmed';
        $this->fileField($form, 'encounter_import[file]')->upload($this->file(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build()));
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(0, $this->entityManager()->getRepository(EncounterImport::class)->findAll());
    }

    public function testUploadIsRefusedWhileTheKeyChangeIsInProgress(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO encounter_key_state (hospital_id, key_change_in_progress, created_at) VALUES (?, true, '2026-11-01 10:00:00')",
            [$this->hospital->getId()],
        );

        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/encounter/imports/new');
        $form = $crawler->filter('form[name="encounter_import"]')->form();
        $form['encounter_import[hospital]'] = (string) $this->hospital->getId();
        $form['encounter_import[name]'] = 'During key change';
        $this->checkbox($form, 'encounter_import[confirmation]')->tick();
        $this->fileField($form, 'encounter_import[file]')->upload($this->file(EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build()));
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(0, $this->entityManager()->getRepository(Import::class)->findAll());
    }

    public function testHospitalMemberWithoutUploadGrantCannotUpload(): void
    {
        $viewer = $this->user();
        $this->hospitalGrant($this->hospital, $viewer);
        $this->encounterGrant($this->hospital, $viewer, [EncounterGrantAction::ViewCases]);

        $this->client->loginUser($viewer);
        $this->client->request(Request::METHOD_GET, '/encounter/imports/new');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testReportShowsCodesButNoCellValuesAndIsHiddenFromOtherUsers(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15')
            ->visit('b', '2026-10-01T09:15', overrides: ['heart_rate' => '377'])
            ->build(), 'with reject');

        $this->client->loginUser($this->uploader);
        $this->client->request(Request::METHOD_GET, '/encounter/imports/'.$importId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="reject-row"]', 'heart_rate: out_of_range');
        self::assertStringNotContainsString('377', (string) $this->client->getResponse()->getContent());

        // The hospital owner has no encounter grant: not found, also on the generic import pages.
        $owner = $this->hospital->getOwner();
        self::assertInstanceOf(User::class, $owner);
        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/encounter/imports/'.$importId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRejectedRawExportShowsTheReasonAndCannotBeReprocessed(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::rawVendorExport(), 'raw');

        $this->client->loginUser($this->uploader);
        $this->client->request(Request::METHOD_GET, '/encounter/imports/'.$importId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="encounter-file-rejection"]');
        self::assertSelectorExists('[data-test="encounter-source-deleted"]');
        self::assertSelectorNotExists('#encounter-import-reprocess-modal');
        self::assertStringNotContainsString('Muster', (string) $this->client->getResponse()->getContent());
    }

    public function testUploaderReprocessesAnImport(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'reprocess me');

        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/encounter/imports/'.$importId);
        $token = $crawler->filter('#encounter-import-reprocess-modal input[name="_token"]')->attr('value');
        $this->client->request(Request::METHOD_POST, '/encounter/imports/'.$importId.'/reprocess', ['_token' => $token]);

        self::assertResponseRedirects('/encounter/imports/'.$importId);
        $this->entityManager()->clear();
        $import = $this->entityManager()->find(Import::class, $importId);
        self::assertSame(2, $import?->getRunCount());
        self::assertSame(1, $this->encounterCount($importId));
    }

    public function testEncounterImportsAreNotListedOnTheGenericImportPage(): void
    {
        $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'hidden encounter import');
        $owner = $this->hospital->getOwner();
        self::assertInstanceOf(User::class, $owner);
        $owner->setRoles(['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_ENCOUNTER_BETA']);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/import');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('hidden encounter import', (string) $this->client->getResponse()->getContent());

        $this->client->loginUser($this->uploader);
        $this->client->request(Request::METHOD_GET, '/encounter/imports');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-import-row"]', 'hidden encounter import');
    }

    private function checkbox(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function fileField(Form $form, string $name): FileFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(FileFormField::class, $field);

        return $field;
    }

    private function file(string $content): string
    {
        $path = sys_get_temp_dir().'/encounter-upload-'.bin2hex(random_bytes(6)).'.csv';
        file_put_contents($path, $content);
        $this->uploads[] = $path;

        return $path;
    }
}
