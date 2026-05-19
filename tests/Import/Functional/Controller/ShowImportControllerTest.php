<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ShowImportControllerTest extends WebTestCase
{
    use HasBrowser;
    use Factories;
    use ResetDatabase;

    public function testShowDisplaysImportDetails(): void
    {
        [$owner, $importId, $name, $hospitalName] = $this->createImportWithRelations();

        $this->browser()
            ->actingAs($owner)
            ->visit(\sprintf('/import/%d', $importId))
            ->assertSuccessful()

            ->assertSeeElement('#import-id')
            ->assertSee('#'.$importId)
            ->assertSee($name)

            ->assertSee($hospitalName)

            ->assertSeeIn('.datagrid', 'CSV')
            ->assertSeeIn('.datagrid', 'Mime Type')
            ->assertSeeIn('.datagrid', 'KB')

            ->assertSee('PENDING')
            ->assertSee('ALLOCATION')
            ->assertSee('0 ms')
            ->assertSee('4')
            ->assertSee('1');
    }

    public function testForeignImportIsNotAccessible(): void
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(4))]);
        $intruder = UserFactory::createOne(['username' => 'intruder-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $import = ImportFactory::createOne([
            'name' => 'Protected Import',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => '/tmp/protected.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 12,
            'rowCount' => 5,
            'rowsPassed' => 4,
            'rowsRejected' => 1,
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ]);

        $this->browser()
            ->actingAs($intruder)
            ->visit(\sprintf('/import/%d', $import->getId()))
            ->assertStatus(403);
    }

    /**
     * @return array{0:User,1:int,2:string,3:string} [Owner, ImportId, ImportName, HospitalName]
     */
    private function createImportWithRelations(): array
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

        $name = 'Test Allocations';
        $hospitalName = $hospital->getName();
        $target = 'dummy/path';

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $freshOwner */
        $freshOwner = $em->getRepository(User::class)->find($owner->getId());
        self::assertNotNull($freshOwner);

        $import = new Import()
            ->setName($name)
            ->setHospital($hospital->_real())
            ->setCreatedBy($freshOwner)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($target)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(\filesize($target) ?: 0)
            ->setFileChecksum('abc123checksum')
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(5)
            ->setRowsPassed(4)
            ->setRowsRejected(1);

        $em->persist($import);
        $em->flush();

        return [$freshOwner, (int) $import->getId(), $name, $hospitalName];
    }
}
