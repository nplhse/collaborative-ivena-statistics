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
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class NewImportControllerTest extends WebTestCase
{
    use HasBrowser;
    use Factories;

    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = \dirname(__DIR__, 2).'/Fixtures';
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
        [$owner, $hospitalId] = $this->createOwnerWithHospital();

        $csvPath = $this->fixturesDir.'/allocation_import_sample.csv';
        self::assertFileExists($csvPath);

        /** @var string $projectDir */
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $uploadDir = $projectDir.'/var/imports/'.date('Y').'/'.date('m');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $previousPerms = fileperms($uploadDir) ?: 0775;
        chmod($uploadDir, 0555);

        try {
            $this->browser()
                ->actingAs($owner)
                ->visit('/import/new')
                ->assertSuccessful()
                ->fillField('import_create[name]', 'Upload Failure Attempt')
                ->selectField('import_create[hospital]', (string) $hospitalId)
                ->attachFile('import_create[file]', $csvPath)
                ->click('import_create[submit]')
                ->assertSuccessful()
                ->assertSee('Excel files (.xls, .xlsx) are not supported. Please export your data as CSV.')
                ->assertSeeElement('.is-invalid[name="import_create[file]"]')
                ->use(function (): void {
                    /** @var EntityManagerInterface $em */
                    $em = self::getContainer()->get(EntityManagerInterface::class);

                    self::assertNull(
                        $em->getRepository(Import::class)->findOneBy(['name' => 'Upload Failure Attempt']),
                    );
                });
        } finally {
            chmod($uploadDir, $previousPerms & 0777);
        }
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
}
