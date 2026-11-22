<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Service\FileUploader;
use App\Import\Application\Service\ImportFileStorage;
use App\Import\Application\Service\ImportHospitalOverride;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\Tests\Support\RateLimit\DeniesRateLimiter;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class NewImportControllerTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use DeniesRateLimiter;
    use Factories;
    use HasBrowser;

    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = \dirname(__DIR__, 2).'/Fixtures';
    }

    public function testParticipantCanUploadAClosureList(): void
    {
        $owner = UserFactory::new()->withoutAutorefresh()->create([
            'username' => 'closure-owner',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $createdBy = UserFactory::new()->withoutAutorefresh()->create(['username' => 'closure-area-user']);
        $state = StateFactory::new()->withoutAutorefresh()->create(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::new()->withoutAutorefresh()->create(['name' => 'Closure Area', 'state' => $state]);
        $hospital = HospitalFactory::new()->withoutAutorefresh()->create([
            'name' => 'Klinikum Beispiel',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
        SpecialityFactory::new()->withoutAutorefresh()->create(['name' => 'Innere Medizin']);
        DepartmentFactory::new()->withoutAutorefresh()->create(['name' => 'Kardiologie']);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        /** @var User $freshOwner */
        $freshOwner = $em->getRepository(User::class)->findOneBy(['username' => 'closure-owner']);
        self::assertNotNull($freshOwner);

        $csvPath = tempnam(sys_get_temp_dir(), 'closure-upload');
        self::assertNotFalse($csvPath);
        $csvFile = $csvPath.'.csv';
        self::assertTrue(rename($csvPath, $csvFile));
        $csvPath = $csvFile;
        $csv = "Krankenhaus-Kurzname;Fachgebiet;Fachbereich;Behandlungsdringlichkeit;Datum (Schließungs-Beginn);Uhrzeit (Schließungs-Beginn);Schließungs-Dauer (Minuten);Datum (Schließungs-Ende);Uhrzeit (Schließungs-Ende);Grund;Eingetragen am;Geändert am;Typ\n";
        $csv .= "Klinikum Beispiel;Innere Medizin;Kardiologie;Notfallversorgung;01.01.2026;00:10:00;60;01.01.2026;01:10:00;Überlastung der Notaufnahme;01.01.2026 00:20:22;01.01.2026 00:20:22;Klinik\n";
        self::assertNotFalse(file_put_contents($csvPath, $csv));

        $this->browser()
            ->actingAs($freshOwner)
            ->interceptRedirects()
            ->visit('/import/new')
            ->assertSuccessful()
            ->assertSeeElement('select[name="import_create[type]"]')
            ->fillField('import_create[name]', 'Test Closure List')
            ->selectField('import_create[hospital]', (string) $hospital->getId())
            ->selectField('import_create[type]', 'Closure')
            ->attachFile('import_create[file]', $csvPath)
            ->click('import_create[submit]')
            ->assertRedirected()
            ->use(function () use ($em): void {
                $em->clear();
                /** @var Import|null $import */
                $import = $em->getRepository(Import::class)->findOneBy(['name' => 'Test Closure List']);
                self::assertNotNull($import);
                self::assertSame(\App\Import\Domain\Enum\ImportType::CLOSURE, $import->getType());
                self::assertTrue($import->isFinalStatus());
                @\unlink((string) $import->getFilePath());
            });

        @\unlink($csvPath);
    }

    public function testFormRendersForLoggedInOwner(): void
    {
        [$owner] = $this->createOwnerWithHospital();

        $this->browser()
            ->actingAs($owner)
            ->visit('/import/new')
            ->assertSuccessful()
            ->assertSeeElement('form')
            ->assertSeeElement('input[name="import_create[name]"]')
            ->assertSeeElement('select[name="import_create[hospital]"]')
            ->assertSeeElement('input[type="file"][name="import_create[file]"]');
    }

    public function testParticipantWithoutUploadRightIsRedirectedToTheImportList(): void
    {
        $this->createOwnerWithHospital();
        $participant = UserFactory::new()->withoutAutorefresh()->create([
            'username' => 'no-upload-user',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);

        $this->browser()
            ->actingAs($participant)
            ->interceptRedirects()
            ->visit('/import/new')
            ->assertRedirectedTo('/import')
            ->assertSeeIn('.messages', 'You cannot start an import yet');
    }

    public function testOwnerIsOfferedAllocationAndClosureImportsForTheHospital(): void
    {
        // Closure lists left the beta (#1350): the import permission is enough.
        [$owner, $hospitalId] = $this->createOwnerWithHospital();

        $this->browser()
            ->actingAs($owner)
            ->visit('/import/new')
            ->assertSuccessful()
            ->assertSeeElement('select[name="import_create[type]"] option[data-import-type="Allocation"]')
            ->assertSeeElement('select[name="import_create[type]"] option[data-import-type="Closure"]')
            ->assertSeeElement(\sprintf('select[name="import_create[hospital]"] option[value="%d"][data-import-types=\'["Allocation","Closure"]\']', $hospitalId));
    }

    public function testSubmitWithCsvRedirectsToProcessingAndPersists(): void
    {
        [$owner, $hospitalId] = $this->createOwnerWithHospital();

        $csvPath = $this->fixturesDir.'/allocation_import_sample.csv';
        self::assertFileExists($csvPath, 'Fixture allocation_import_sample.csv fehlt');

        $this->browser()
            ->actingAs($owner)
            ->interceptRedirects()
            ->visit('/import/new')
            ->assertSuccessful()
            ->fillField('import_create[name]', 'Test Allocations')
            ->selectField('import_create[hospital]', (string) $hospitalId)
            ->attachFile('import_create[file]', $csvPath)
            ->click('import_create[submit]')
            ->assertRedirected()
            ->followRedirect()
            ->assertSuccessful()
            ->assertSee('Test Allocations')
            ->assertSee('New Import has been created successfully!')
            ->assertSeeElement('ul.steps.steps-vertical')
            ->assertSeeElement('[data-controller="import-status"]')
            ->assertSee('File uploaded')
            ->use(function (): void {
                /** @var EntityManagerInterface $em */
                $em = self::getContainer()->get(EntityManagerInterface::class);

                /** @var Import|null $import */
                $import = $em->getRepository(Import::class)->findOneBy(['name' => 'Test Allocations']);
                self::assertNotNull($import, 'Import has been persisted.');
                self::assertTrue($import->isFinalStatus(), 'Import is processed synchronously in the test environment.');

                $runs = self::getContainer()->get(ImportRunRepository::class)->findByImportId((int) $import->getId());
                self::assertCount(1, $runs, 'The upload registers exactly one run.');
                self::assertTrue($runs[0]->isFinished());
                self::assertSame(ImportRunTrigger::Upload, $runs[0]->getTrigger());
                self::assertEquals($runs[0]->getId(), $import->getCurrentRunId());
                self::assertSame($import->getCreatedBy()?->getId(), $runs[0]->getInitiatedBy()?->getId());
                self::assertSame(1, $import->getRunCount());

                $types = self::getContainer()->get(Connection::class)->fetchFirstColumn(
                    "SELECT type FROM notification WHERE recipient_id = ? AND type IN ('import.completed', 'import.failed')",
                    [$import->getCreatedBy()?->getId()],
                );
                self::assertCount(1, $types, 'The finished run is announced once to its creator (ADR 016).');

                self::assertNotEmpty($import->getFileMimeType());
                self::assertTrue(
                    str_contains((string) $import->getFileMimeType(), 'csv') || 'application/octet-stream' === $import->getFileMimeType()
                );
                self::assertGreaterThan(0, $import->getFileSize());
                self::assertNotSame('', (string) $import->getFileChecksum());

                @\unlink($import->getFilePath());
            })
            ->assertSee('View import details')
            ->assertSeeElement('[data-import-status-target="detailLink"]:not(.d-none)');
    }

    public function testRememberMeParticipantCanCreateImportWithoutPasswordConfirmation(): void
    {
        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $username = $owner->getUsername();
        self::assertNotNull($username);

        $client = $this->browser()->client();
        $client->followRedirects(false);
        $this->loginWithRememberMe($client, $username);
        $cookies = $this->extractRememberMeSessionCookies($client);
        $this->useRememberMeSessionOnly($client, $cookies['rememberMe'], $cookies['consentSubject']);

        $csvPath = $this->fixturesDir.'/allocation_import_sample.csv';
        self::assertFileExists($csvPath, 'Fixture allocation_import_sample.csv fehlt');
        $uploadPath = sys_get_temp_dir().'/allocation_import_sample_'.bin2hex(random_bytes(4)).'.csv';
        self::assertTrue(copy($csvPath, $uploadPath));

        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
        self::assertSelectorExists('input[name="import_create[name]"]');
        self::assertStringNotContainsString('Confirm your password', (string) $client->getResponse()->getContent());

        $client->disableReboot();
        $form = $crawler->filter('form')->form();
        $client->request(
            Request::METHOD_POST,
            '/import/new',
            [
                'import_create' => [
                    'name' => 'Remember Me Import',
                    'hospital' => (string) $hospitalId,
                    '_token' => $form['import_create[_token]']->getValue(),
                    'type' => $this->allocationType($crawler),
                ],
            ],
            [
                'import_create' => [
                    'file' => new UploadedFile($uploadPath, 'allocation_import_sample.csv', 'text/csv', null, true),
                ],
            ],
        );

        self::assertResponseRedirects();
        $location = $client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringNotContainsString('/login/confirm', $location);
        self::assertMatchesRegularExpression('#/import/\d+/processing$#', $location);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Remember Me Import');
        self::assertSelectorTextContains('body', 'New Import has been created successfully!');

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var Import|null $import */
        $import = $em->getRepository(Import::class)->findOneBy(['name' => 'Remember Me Import']);
        self::assertNotNull($import, 'Import has been persisted.');
        @\unlink($import->getFilePath());
    }

    public function testSubmitIsRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $ownerId = $owner->getId();
        self::assertNotNull($ownerId);

        $csvPath = $this->fixturesDir.'/allocation_import_sample.csv';
        self::assertFileExists($csvPath);

        $client->loginUser($owner);

        $this->denyRateLimiter(
            'limiter.import_create',
            $this->userAndIpRateLimitKey('import_create', $ownerId),
        );

        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $client->request(
            Request::METHOD_POST,
            '/import/new',
            [
                'import_create' => [
                    'name' => 'Rate Limited Import',
                    'hospital' => (string) $hospitalId,
                    '_token' => $form['import_create[_token]']->getValue(),
                    'type' => $this->allocationType($crawler),
                ],
            ],
            [
                'import_create' => [
                    'file' => new UploadedFile($this->uploadCopy($csvPath), 'allocation_import_sample.csv', 'text/csv', null, true),
                ],
            ],
        );

        self::assertResponseRedirects('/import/new');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Too many import attempts');

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(Import::class)->findOneBy(['name' => 'Rate Limited Import']));
    }

    public function testHospitalOverrideIsUnavailableOutsideDevelopment(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[name="import_create[hospitalOverride]"]');
        self::assertSelectorNotExists('[data-test="import-options"]', 'Without any available option there is no Options section.');

        $this->postAllocationUpload($client, $crawler, 'Forged Override Import', $hospitalId, ['hospitalOverride' => '1']);

        // The field does not exist outside dev, so the posted value is an extra field and the form is invalid.
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="import_create"]');
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertNull($em->getRepository(Import::class)->findOneBy(['name' => 'Forged Override Import']));
    }

    public function testHospitalOverrideIsRecordedInDevelopment(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(ImportHospitalOverride::class, new ImportHospitalOverride('dev'));
        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="import-options"] input[type="checkbox"][name="import_create[hospitalOverride]"]');
        self::assertSelectorTextContains('[data-test="import-options"] label', 'Override target hospital');
        self::assertSelectorTextNotContains('[data-test="import-options"] label', 'development only');
        self::assertSelectorNotExists('#import-options-body.show', 'The Options section starts collapsed.');
        $this->postAllocationUpload($client, $crawler, 'Override Import', $hospitalId, ['hospitalOverride' => '1']);
        self::assertResponseRedirects();
        $this->postAllocationUpload($client, $crawler, 'Plain Import', $hospitalId);
        self::assertResponseRedirects();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $override = $em->getRepository(Import::class)->findOneBy(['name' => 'Override Import']);
        self::assertInstanceOf(Import::class, $override);
        self::assertTrue($override->isHospitalOverride());
        self::assertSame($hospitalId, $override->getHospital()?->getId(), 'The selected hospital stays the target.');
        self::assertGreaterThan(0, (int) $override->getRowsPassed(), 'Allocation rows are stored on the selected hospital.');

        $plain = $em->getRepository(Import::class)->findOneBy(['name' => 'Plain Import']);
        self::assertInstanceOf(Import::class, $plain);
        self::assertFalse($plain->isHospitalOverride(), 'Without the checkbox nothing is overridden.');

        $client->request(Request::METHOD_GET, '/import/'.$override->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Imported with the target hospital override.');

        @\unlink((string) $override->getFilePath());
        @\unlink((string) $plain->getFilePath());
    }

    public function testSubmitWithXlsxShowsValidationErrorAndDoesNotPersist(): void
    {
        [$owner, $hospitalId] = $this->createOwnerWithHospital();

        $xlsxPath = $this->fixturesDir.'/sample.xlsx';
        self::assertFileExists($xlsxPath, 'Fixture sample.xlsx fehlt');

        $this->browser()
            ->actingAs($owner)
            ->visit('/import/new')
            ->assertSuccessful()
            ->fillField('import_create[name]', 'Excel Import Attempt')
            ->selectField('import_create[hospital]', (string) $hospitalId)
            ->attachFile('import_create[file]', $xlsxPath)
            ->click('import_create[submit]')
            ->assertSuccessful()
            ->assertSee('Excel files (.xls, .xlsx) are not supported. Please export your data as CSV.')
            ->assertSeeElement('.is-invalid[name="import_create[file]"]')
            ->assertSeeElement('#import_create_file_error1')
            ->use(function (): void {
                /** @var EntityManagerInterface $em */
                $em = self::getContainer()->get(EntityManagerInterface::class);

                self::assertNull(
                    $em->getRepository(Import::class)->findOneBy(['name' => 'Excel Import Attempt']),
                    'Excel upload must not create an import.',
                );
            });
    }

    public function testSubmitWithXlsShowsValidationErrorAndDoesNotPersist(): void
    {
        [$owner, $hospitalId] = $this->createOwnerWithHospital();

        $xlsPath = $this->fixturesDir.'/sample.xls';
        copy($this->fixturesDir.'/sample.xlsx', $xlsPath);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import/new')
            ->assertSuccessful()
            ->fillField('import_create[name]', 'Excel XLS Import Attempt')
            ->selectField('import_create[hospital]', (string) $hospitalId)
            ->attachFile('import_create[file]', $xlsPath)
            ->click('import_create[submit]')
            ->assertSuccessful()
            ->assertSee('Excel files (.xls, .xlsx) are not supported. Please export your data as CSV.')
            ->use(function (): void {
                /** @var EntityManagerInterface $em */
                $em = self::getContainer()->get(EntityManagerInterface::class);

                self::assertNull(
                    $em->getRepository(Import::class)->findOneBy(['name' => 'Excel XLS Import Attempt']),
                    'Excel upload must not create an import.',
                );
            });
    }

    public function testSubmitWithPdfShowsUnsupportedExtensionError(): void
    {
        [$owner, $hospitalId] = $this->createOwnerWithHospital();

        $pdfPath = sys_get_temp_dir().'/import_report_'.bin2hex(random_bytes(4)).'.pdf';
        file_put_contents($pdfPath, '%PDF-1.4');

        try {
            $this->browser()
                ->actingAs($owner)
                ->visit('/import/new')
                ->assertSuccessful()
                ->fillField('import_create[name]', 'PDF Import Attempt')
                ->selectField('import_create[hospital]', (string) $hospitalId)
                ->attachFile('import_create[file]', $pdfPath)
                ->click('import_create[submit]')
                ->assertSuccessful()
                ->assertSee('Only files with the extension .csv or .txt are allowed.')
                ->use(function (): void {
                    /** @var EntityManagerInterface $em */
                    $em = self::getContainer()->get(EntityManagerInterface::class);

                    self::assertNull(
                        $em->getRepository(Import::class)->findOneBy(['name' => 'PDF Import Attempt']),
                    );
                });
        } finally {
            @unlink($pdfPath);
        }
    }

    public function testSubmitWithoutFileShowsValidationError(): void
    {
        [$owner, $hospitalId] = $this->createOwnerWithHospital();

        $this->browser()
            ->actingAs($owner)
            ->visit('/import/new')
            ->assertSuccessful()
            ->fillField('import_create[name]', 'Missing File Attempt')
            ->selectField('import_create[hospital]', (string) $hospitalId)
            ->click('import_create[submit]')
            ->assertSuccessful()
            ->assertSeeElement('.is-invalid[name="import_create[file]"]')
            ->use(function (): void {
                /** @var EntityManagerInterface $em */
                $em = self::getContainer()->get(EntityManagerInterface::class);

                self::assertNull(
                    $em->getRepository(Import::class)->findOneBy(['name' => 'Missing File Attempt']),
                );
            });
    }

    public function testSubmitShowsFieldErrorWhenUploadMoveFails(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        [$owner, $hospitalId] = $this->createOwnerWithHospital();

        $csvPath = $this->fixturesDir.'/allocation_import_sample.csv';
        self::assertFileExists($csvPath);

        /** @var string $importsBaseDir */
        $importsBaseDir = self::getContainer()->getParameter('app.imports_base_dir');
        $isolatedBase = Path::join($importsBaseDir, '_chmod_'.bin2hex(random_bytes(4)));
        $uploadDir = Path::join($isolatedBase, date('Y'), date('m'));
        $filesystem = self::getContainer()->get(Filesystem::class);
        $filesystem->mkdir($uploadDir, 0775);

        $isolatedUploader = new FileUploader(
            $isolatedBase,
            self::getContainer()->get(ImportFileStorage::class),
            self::getContainer()->get('monolog.logger'),
            $filesystem,
            new NativeClock(),
        );
        self::getContainer()->set(FileUploader::class, $isolatedUploader);

        $previousPerms = fileperms($uploadDir) ?: 0775;
        chmod($uploadDir, 0555);

        $client->loginUser($owner);

        try {
            if (is_writable($uploadDir)) {
                // Root (for example in a container) writes to directories regardless of their mode (#1464).
                self::markTestSkipped('Directory permissions are not enforced for this user.');
            }

            $crawler = $client->request(Request::METHOD_GET, '/import/new');
            self::assertResponseIsSuccessful();

            $form = $crawler->filter('form')->form();
            $client->request(
                Request::METHOD_POST,
                '/import/new',
                [
                    'import_create' => [
                        'name' => 'Upload Failure Attempt',
                        'hospital' => (string) $hospitalId,
                        '_token' => $form['import_create[_token]']->getValue(),
                        'type' => $this->allocationType($crawler),
                    ],
                ],
                [
                    'import_create' => [
                        'file' => new UploadedFile($this->uploadCopy($csvPath), 'allocation_import_sample.csv', 'text/csv', null, true),
                    ],
                ],
            );

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Excel files (.xls, .xlsx) are not supported. Please export your data as CSV.');
            self::assertSelectorExists('.is-invalid[name="import_create[file]"]');

            /** @var EntityManagerInterface $em */
            $em = self::getContainer()->get(EntityManagerInterface::class);
            self::assertNull(
                $em->getRepository(Import::class)->findOneBy(['name' => 'Upload Failure Attempt']),
            );
        } finally {
            chmod($uploadDir, $previousPerms & 0777);
            if ($filesystem->exists($isolatedBase)) {
                $filesystem->remove($isolatedBase);
            }
        }
    }

    /**
     * Name, hospital and file had no label key, so the German form showed "Name", "Hospital" and "File" (#1412).
     */
    #[DataProvider('formLabelsByLocale')]
    public function testUploadFormLabelsAreTranslated(string $locale, string $name, string $hospital, string $file): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$owner] = $this->createOwnerWithHospital();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $owner->setLocale($locale);
        $em->flush();
        $client->loginUser($owner);

        $client->request(Request::METHOD_GET, '/import/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('label[for="import_create_name"]', $name);
        self::assertSelectorTextSame('label[for="import_create_hospital"]', $hospital);
        self::assertSelectorTextSame('label[for="import_create_file"]', $file);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function formLabelsByLocale(): iterable
    {
        yield 'en' => ['en', 'Name', 'Hospital', 'File'];
        yield 'de' => ['de', 'Name', 'Krankenhaus', 'Datei'];
    }

    /**
     * @return array{0:User,1:int} [Owner, HospitalId]
     */
    private function createOwnerWithHospital(): array
    {
        $owner = UserFactory::new()->withoutAutorefresh()->create([
            'username' => 'owner-user',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $createdBy = UserFactory::new()->withoutAutorefresh()->create(['username' => 'area-user']);
        $state = StateFactory::new()->withoutAutorefresh()->create(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::new()->withoutAutorefresh()->create(['name' => 'Test Area', 'state' => $state]);

        $hospital = HospitalFactory::new()->withoutAutorefresh()->create([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::new()->withoutAutorefresh()->create(['name' => 'Innere Medizin']);
        DepartmentFactory::new()->withoutAutorefresh()->create(['name' => 'Kardiologie']);
        AssignmentFactory::new()->withoutAutorefresh()->create(['name' => 'Patient']);
        AssignmentFactory::new()->withoutAutorefresh()->create(['name' => 'RD']);
        AssignmentFactory::new()->withoutAutorefresh()->create(['name' => 'ZLST']);
        OccasionFactory::new()->withoutAutorefresh()->create(['name' => 'aus Arztpraxis']);
        OccasionFactory::new()->withoutAutorefresh()->create(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::new()->withoutAutorefresh()->create(['name' => 'Öffentlicher Raum']);
        OccasionFactory::new()->withoutAutorefresh()->create(['name' => 'Sonstiger Einsatz']);
        SecondaryTransportFactory::new()->withoutAutorefresh()->create(['name' => 'Kapazitätsengpass']);
        InfectionFactory::new()->withoutAutorefresh()->create(['name' => 'Noro']);
        InfectionFactory::new()->withoutAutorefresh()->create(['name' => 'V.a. COVID']);
        IndicationRawFactory::new()->withoutAutorefresh()->create(['name' => 'Test Indication', 'code' => 123, 'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4']);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        /** @var User $freshOwner */
        $freshOwner = $em->getRepository(User::class)->findOneBy(['username' => 'owner-user']);
        self::assertNotNull($freshOwner);

        return [$freshOwner, (int) $hospital->getId()];
    }

    public function testOptionsSectionOpensWhenAnOptionIsSetAndTheFormIsShownAgain(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(ImportHospitalOverride::class, new ImportHospitalOverride('demo'));
        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-test="import-options"] [name="import_create[hospitalOverride]"]', 'The option is offered in the demo.');

        // An empty name makes the form invalid; the ticked option keeps the section open.
        $this->postAllocationUpload($client, $crawler, '', $hospitalId, ['hospitalOverride' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#import-options-body.show');
        self::assertCheckboxChecked('import_create[hospitalOverride]');
    }

    public function testSubmitWithAnUploadTokenImportsTheBackgroundUploadOnce(): void
    {
        $client = self::createClient();
        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        $token = $this->uploadInBackground($client, $crawler);
        $pendingFile = $this->pendingDir().'/'.$owner->getId().'/'.$token.'.upload';
        self::assertFileExists($pendingFile);

        $this->postWithUploadToken($client, $crawler, 'Background Upload', $hospitalId, $token);

        self::assertResponseRedirects();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $imports = $em->getRepository(Import::class)->findBy(['name' => 'Background Upload']);
        self::assertCount(1, $imports);
        self::assertSame('csv', $imports[0]->getFileExtension());
        self::assertGreaterThan(0, $imports[0]->getFileSize());
        self::assertFileDoesNotExist($pendingFile, 'The pending file moved into the import storage.');
        self::assertFileDoesNotExist($this->pendingDir().'/'.$owner->getId().'/'.$token.'.json');

        // A second submission of the same token (double click, back button) finds no file and creates nothing.
        $this->postWithUploadToken($client, $crawler, 'Background Upload', $hospitalId, $token);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $em->getRepository(Import::class)->findBy(['name' => 'Background Upload']));
        @\unlink(self::getContainer()->get(ImportFileStorage::class)->resolve((string) $imports[0]->getFilePath()));
    }

    public function testAnUploadTokenOfAnotherUserIsNotUsed(): void
    {
        $client = self::createClient();
        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $otherOwner = UserFactory::createOne(['username' => 'other-owner', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        HospitalFactory::createOne(['name' => 'Other Hospital', 'owner' => $otherOwner]);

        $client->loginUser($otherOwner);
        $foreignToken = $this->uploadInBackground($client, $client->request(Request::METHOD_GET, '/import/new'));

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        $this->postWithUploadToken($client, $crawler, 'Foreign Upload', $hospitalId, $foreignToken);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#import_create_file.is-invalid');
        self::assertSame('', $client->getCrawler()->filter('form')->attr('data-import-upload-pending-name-value'));
        self::assertNull(self::getContainer()->get(EntityManagerInterface::class)->getRepository(Import::class)->findOneBy(['name' => 'Foreign Upload']));
        self::assertFileExists($this->pendingDir().'/'.$otherOwner->getId().'/'.$foreignToken.'.upload', 'The other user keeps the file.');
    }

    public function testAFileSentWithTheFormWinsOverAnUploadToken(): void
    {
        $client = self::createClient();
        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        $token = $this->uploadInBackground($client, $crawler);

        $this->postAllocationUpload($client, $crawler, 'Direct Upload', $hospitalId, ['uploadToken' => $token]);

        self::assertResponseRedirects();
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $import = $em->getRepository(Import::class)->findOneBy(['name' => 'Direct Upload']);
        self::assertNotNull($import);
        self::assertFileExists($this->pendingDir().'/'.$owner->getId().'/'.$token.'.upload', 'The background upload was not used; the hourly job removes it.');
        @\unlink(self::getContainer()->get(ImportFileStorage::class)->resolve((string) $import->getFilePath()));
    }

    public function testTheBackgroundUploadSurvivesAnErrorInAnotherField(): void
    {
        $client = self::createClient();
        [$owner, $hospitalId] = $this->createOwnerWithHospital();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/import/new');
        $token = $this->uploadInBackground($client, $crawler);

        $this->postWithUploadToken($client, $crawler, '', $hospitalId, $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#import_create_name.is-invalid');
        self::assertSelectorNotExists('#import_create_file.is-invalid');
        self::assertSame($token, $client->getCrawler()->filter('#import_create_uploadToken')->attr('value'));
        self::assertSame('allocation_import_sample.csv', $client->getCrawler()->filter('form')->attr('data-import-upload-pending-name-value'));
        self::assertFileExists($this->pendingDir().'/'.$owner->getId().'/'.$token.'.upload');
    }

    private function uploadInBackground(KernelBrowser $client, Crawler $crawler): string
    {
        $uploadPath = sys_get_temp_dir().'/allocation_import_sample_'.bin2hex(random_bytes(4)).'.csv';
        self::assertTrue(copy($this->fixturesDir.'/allocation_import_sample.csv', $uploadPath));

        $client->request(
            Request::METHOD_POST,
            '/import/upload',
            ['_token' => (string) $crawler->filter('form')->attr('data-import-upload-csrf-value')],
            ['file' => new UploadedFile($uploadPath, 'allocation_import_sample.csv', 'text/csv', null, true)],
        );
        self::assertResponseStatusCodeSame(201);

        /** @var array{token: string} $answer */
        $answer = json_decode((string) $client->getResponse()->getContent(), true, 4, \JSON_THROW_ON_ERROR);

        return $answer['token'];
    }

    private function postWithUploadToken(KernelBrowser $client, Crawler $crawler, string $name, int $hospitalId, string $uploadToken): void
    {
        $client->request(Request::METHOD_POST, '/import/new', ['import_create' => [
            'name' => $name,
            'hospital' => (string) $hospitalId,
            'type' => $this->allocationType($crawler),
            'uploadToken' => $uploadToken,
            '_token' => $crawler->filter('form')->form()['import_create[_token]']->getValue(),
        ]]);
    }

    private function pendingDir(): string
    {
        $baseDir = self::getContainer()->getParameter('app.imports_base_dir');
        self::assertIsString($baseDir);

        return $baseDir.'/pending';
    }

    /**
     * @param array<string, string> $extra additional form fields
     */
    private function postAllocationUpload(KernelBrowser $client, Crawler $crawler, string $name, int $hospitalId, array $extra = []): void
    {
        $token = $crawler->filter('form')->form()['import_create[_token]']->getValue();
        $uploadPath = sys_get_temp_dir().'/allocation_import_sample_'.bin2hex(random_bytes(4)).'.csv';
        self::assertTrue(copy($this->fixturesDir.'/allocation_import_sample.csv', $uploadPath));

        $client->request(
            Request::METHOD_POST,
            '/import/new',
            ['import_create' => ['name' => $name, 'hospital' => (string) $hospitalId, 'type' => $this->allocationType($crawler), '_token' => $token, ...$extra]],
            ['import_create' => ['file' => new UploadedFile($uploadPath, 'allocation_import_sample.csv', 'text/csv', null, true)]],
        );
    }

    /**
     * Value of the allocation option in the type field; every user with the import permission chooses
     * between allocation and closure lists (#1350).
     */
    private function allocationType(Crawler $crawler): string
    {
        return (string) $crawler->filter('select[name="import_create[type]"] option[data-import-type="Allocation"]')->attr('value');
    }

    /**
     * A temporary copy of a fixture for an upload: a successful upload moves the file, which must never be a tracked
     * fixture (#1464).
     */
    private function uploadCopy(string $fixturePath): string
    {
        $uploadPath = sys_get_temp_dir().'/'.pathinfo($fixturePath, \PATHINFO_FILENAME).'_'.bin2hex(random_bytes(4)).'.csv';
        self::assertTrue(copy($fixturePath, $uploadPath));

        return $uploadPath;
    }
}
