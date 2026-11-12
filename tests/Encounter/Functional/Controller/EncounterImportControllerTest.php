<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Import\EncounterSourceFileRetention;
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
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Encounter imports on the generic import pages (#1048, ADR 027): upload form, list, detail page,
 * reprocess, download and delete, all with the encounter grant UPLOAD; reprocess and download only while
 * the upload file is kept (#1049).
 */
#[ResetDatabase]
final class EncounterImportControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
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
        $filesystem->remove($this->uploads);
        foreach ($this->entityManager()->getRepository(Import::class)->findAll() as $import) {
            $filesystem->remove($this->projectDir().'/'.$import->getFilePath());
        }

        parent::tearDown();
    }

    public function testUploaderUploadsAnEncounterFileWithTheImportForm(): void
    {
        $this->submitUpload('October visits', EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->visit('b', '2026-10-01T09:15')->build());

        $import = $this->entityManager()->getRepository(Import::class)->findOneBy(['name' => 'October visits']);
        self::assertInstanceOf(Import::class, $import);
        self::assertResponseRedirects('/import/'.$import->getId().'/processing');
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
        self::assertStringContainsString('import.created', $audit);

        $this->client->request(Request::METHOD_GET, '/import/'.$import->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="count-inserted"]', '2');
    }

    public function testFormOffersTheToolLinkAndEncounterFields(): void
    {
        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('select[name="import_create[type]"]', 'Encounter data (beta)');
        self::assertSelectorExists('[data-test="import-type-extension-fields"] a[href="/encounter"]');
        // The encounter fields are shown only while the encounter type is selected (import-type-fields controller).
        self::assertSelectorExists('[data-test="import-type-extension-fields"][data-import-type-fields-target="section"]');
        $encounterOption = $crawler->filter('select[name="import_create[type]"] option')
            ->reduce(static fn ($option): bool => str_contains($option->text(), 'Encounter data'))
            ->attr('value');
        self::assertSame(json_encode([$encounterOption]), $crawler->filter('form[name="import_create"]')->attr('data-import-type-fields-types-value'));
        self::assertCount(1, $crawler->filter('select[name="import_create[hospital]"] option[value="'.$this->hospital->getId().'"]'));
    }

    public function testUploadWithoutConfirmationIsRefused(): void
    {
        $this->submitUpload('Unconfirmed', EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), confirm: false);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.invalid-feedback, .form-error-message, .invalid-feedback.d-block');
        self::assertCount(0, $this->entityManager()->getRepository(Import::class)->findAll());
    }

    public function testUploadIsRefusedWhileTheKeyChangeIsInProgress(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO encounter_key_state (hospital_id, key_change_in_progress, created_at) VALUES (?, true, '2026-11-01 10:00:00')",
            [$this->hospital->getId()],
        );

        $this->submitUpload('During key change', EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->entityManager()->getRepository(Import::class)->findAll());
    }

    public function testHospitalMemberWithoutUploadGrantIsNotOfferedEncounterUploads(): void
    {
        $viewer = $this->user();
        $this->hospitalGrant($this->hospital, $viewer);
        $this->encounterGrant($this->hospital, $viewer, [EncounterGrantAction::ViewCases]);

        $this->client->loginUser($viewer);
        $this->client->request(Request::METHOD_GET, '/import/new');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Encounter data (beta)', (string) $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('[data-test="import-type-extension-fields"]');
    }

    public function testDetailPageShowsCodesButNoCellValuesAndIsHiddenFromUsersWithoutGrant(): void
    {
        // The rejected cell value is a 5-digit sentinel (range 20–300) that does not occur on the page by
        // chance; a short value such as 377 also matched a random CSRF token in CI.
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()
            ->visit('a', '2026-10-01T08:15')
            ->visit('b', '2026-10-01T09:15', overrides: ['heart_rate' => '98765'])
            ->build(), 'with reject');

        $this->client->loginUser($this->uploader);
        $this->client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="reject-row"]', 'heart_rate: out_of_range');
        self::assertStringNotContainsString('98765', (string) $this->client->getResponse()->getContent());

        // The hospital owner has hospital access but no encounter grant (ADR 027).
        $this->client->loginUser($this->owner());
        $this->client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRejectedRawExportShowsTheReasonAndCannotBeReprocessed(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::rawVendorExport(), 'raw');

        $this->client->loginUser($this->uploader);
        $this->client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="encounter-file-rejection"]');
        self::assertSelectorExists('[data-test="encounter-source-deleted"]');
        self::assertSelectorNotExists('#import-reprocess-modal');
        self::assertStringNotContainsString('Muster', (string) $this->client->getResponse()->getContent());
    }

    public function testUploaderReprocessesAnImportAfterTheWarning(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'reprocess me');

        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/'.$importId);
        $modal = $crawler->filter('#import-reprocess-modal');
        self::assertStringContainsString('review decisions', $modal->text());
        $this->client->request(Request::METHOD_POST, '/import/'.$importId.'/reprocess', ['_token' => $modal->filter('input[name="_token"]')->attr('value')]);

        self::assertResponseRedirects('/import/'.$importId.'/processing');
        $this->entityManager()->clear();
        self::assertSame(2, $this->entityManager()->find(Import::class, $importId)?->getRunCount());
        self::assertSame(1, $this->encounterCount($importId));
    }

    public function testUploaderDownloadsAndReprocessesOnlyWhileTheFileIsKept(): void
    {
        self::mockTime('2026-10-01 10:00:00');
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->build(), 'retained');

        self::mockTime('2026-10-30 10:00:00');
        $this->client->loginUser($this->uploader);
        $this->client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-source-file"]', 'Kept until Oct 31, 2026 (30 days after upload)');
        self::assertSelectorExists('a[href="/import/'.$importId.'/source-file"]');
        self::assertSelectorExists('#import-reprocess-modal');
        $this->client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');
        self::assertResponseIsSuccessful();

        self::mockTime('2026-11-01 10:00:00');
        $retention = self::getContainer()->get(EncounterSourceFileRetention::class);
        self::assertInstanceOf(EncounterSourceFileRetention::class, $retention);
        self::assertSame([$importId], $retention->run()->deleted);

        $this->client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-test="encounter-source-file"]', 'File deleted on Nov 1, 2026');
        self::assertSelectorNotExists('a[href="/import/'.$importId.'/source-file"]');
        self::assertSelectorNotExists('#import-reprocess-modal');
        self::assertSelectorNotExists('[data-test="encounter-source-deleted"]', 'The immediate-deletion notice is only for identifying content.');
        self::assertSelectorTextContains('[data-test="count-inserted"]', '1');

        $this->client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->client->request(Request::METHOD_POST, '/import/'.$importId.'/reprocess', ['_token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(1, $this->encounterCount($importId), 'Imported encounters stay.');
    }

    public function testOnlyUploadersAndAdministratorsDownloadTheEncounterFile(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-09-30T08:15')->build(), 'download rights');
        $viewer = $this->user();
        $this->hospitalGrant($this->hospital, $viewer);
        $this->encounterGrant($this->hospital, $viewer, [EncounterGrantAction::ViewCases, EncounterGrantAction::Review]);

        foreach ([$viewer, $this->owner()] as $user) {
            $this->client->loginUser($user);
            $this->client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        }

        $this->client->loginUser($this->admin());
        $this->client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');
        self::assertResponseIsSuccessful();
    }

    public function testUploaderDeletesAnImportAndOnlyItsVisits(): void
    {
        $kept = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'kept');
        $deleted = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->visit('b', '2026-10-01T09:15')->build(), 'to delete');
        self::assertSame(1, $this->encounterCount($deleted));
        $import = $this->entityManager()->find(Import::class, $deleted);
        self::assertInstanceOf(Import::class, $import);
        $sourcePath = $this->projectDir().'/'.$import->getFilePath();
        self::assertFileExists($sourcePath);

        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/'.$deleted);
        $modal = $crawler->filter('#import-delete-modal');
        self::assertStringContainsString('Visits of other imports stay', $modal->text());
        $this->client->request(Request::METHOD_POST, '/import/'.$deleted.'/delete', ['_token' => $modal->filter('input[name="_token"]')->attr('value')]);

        self::assertResponseRedirects('/import');
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Import::class, $deleted));
        self::assertSame(0, $this->encounterCount($deleted));
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_import WHERE import_id = ?', [$deleted]));
        self::assertFileDoesNotExist($sourcePath);
        self::assertSame(1, $this->encounterCount($kept), 'Visits of other imports stay.');
        self::assertSame(1, (int) $this->connection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_class = ? AND entity_id = ? AND action = 'delete'",
            [Import::class, (string) $deleted],
        ));
    }

    public function testViewCasesUserAndOwnerWithoutGrantCannotDelete(): void
    {
        $importId = $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'protected');
        $viewer = $this->user();
        $this->hospitalGrant($this->hospital, $viewer);
        $this->encounterGrant($this->hospital, $viewer, [EncounterGrantAction::ViewCases]);

        foreach ([$viewer, $this->owner()] as $user) {
            $this->client->loginUser($user);
            $this->client->request(Request::METHOD_POST, '/import/'.$importId.'/delete', ['_token' => 'irrelevant']);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        }

        self::assertSame(1, $this->encounterCount($importId));
    }

    public function testImportListShowsEncounterImportsOnlyToUploaders(): void
    {
        $this->uploadEncounterFile($this->hospital, $this->uploader, EdVisitFileBuilder::create()->visit('a', '2026-10-01T08:15')->build(), 'listed encounter import');

        $this->client->loginUser($this->owner());
        $this->client->request(Request::METHOD_GET, '/import');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('listed encounter import', (string) $this->client->getResponse()->getContent());

        $this->client->loginUser($this->uploader);
        $this->client->request(Request::METHOD_GET, '/import');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('listed encounter import', (string) $this->client->getResponse()->getContent());
    }

    private function submitUpload(string $name, string $content, bool $confirm = true): void
    {
        $this->client->loginUser($this->uploader);
        $crawler = $this->client->request(Request::METHOD_GET, '/import/new');
        $form = $crawler->filter('form[name="import_create"]')->form();
        $form['import_create[name]'] = $name;
        $form['import_create[hospital]'] = (string) $this->hospital->getId();
        $form['import_create[type]'] = $crawler->filter('select[name="import_create[type]"] option')
            ->reduce(static fn ($option): bool => str_contains($option->text(), 'Encounter data'))
            ->attr('value') ?? '';
        $form['import_create[encounterSchema]'] = 'ed_visit';
        if ($confirm) {
            $this->checkbox($form, 'import_create[encounterConfirmation]')->tick();
        }

        $this->fileField($form, 'import_create[file]')->upload($this->file($content));
        $this->client->submit($form);
    }

    private function owner(): User
    {
        $owner = $this->hospital->getOwner();
        self::assertInstanceOf(User::class, $owner);

        return $owner;
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
