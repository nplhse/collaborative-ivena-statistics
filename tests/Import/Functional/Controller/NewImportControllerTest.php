<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class NewImportControllerTest extends WebTestCase
{
    use HasBrowser;
    use Factories;
    use ResetDatabase;

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

    public function testSubmitWithCsvRedirectsToShowAndPersists(): void
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
            ->use(function (): void {
                /** @var EntityManagerInterface $em */
                $em = static::getContainer()->get(EntityManagerInterface::class);

                /** @var Import|null $import */
                $import = $em->getRepository(Import::class)->findOneBy(['name' => 'Test Allocations']);
                self::assertNotNull($import, 'Import has been persisted.');

                self::assertNotEmpty($import->getFileMimeType());
                self::assertTrue(
                    str_contains($import->getFileMimeType(), 'csv') || 'application/octet-stream' === $import->getFileMimeType()
                );
                self::assertGreaterThan(0, $import->getFileSize());
                self::assertNotSame('', (string) $import->getFileChecksum());

                @\unlink($import->getFilePath());
            });
    }

    /**
     * @return array{0:User,1:int} [Owner, HospitalId]
     */
    private function createOwnerWithHospital(): array
    {
        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        /** @var User $freshOwner */
        $freshOwner = $em->getRepository(User::class)->find($owner->getId());
        self::assertNotNull($freshOwner);

        return [$freshOwner, (int) $hospital->getId()];
    }
}
