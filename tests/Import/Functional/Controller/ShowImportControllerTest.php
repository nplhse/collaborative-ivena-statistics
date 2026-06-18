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
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowImportControllerTest extends WebTestCase
{
    use Factories;

    public function testShowDisplaysImportDetails(): void
    {
        $client = self::createClient();
        [$owner, $importId, $name, $hospitalName] = $this->createImportWithRelations();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $importId));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#import-id');
        self::assertSelectorTextContains('#import-id', '#'.$importId);
        self::assertSelectorTextContains('body', $name);
        self::assertSelectorTextContains('body', $hospitalName);
        self::assertSelectorTextContains('.datagrid', 'CSV');
        self::assertSelectorTextContains('.datagrid', 'Mime Type');
        self::assertSelectorTextContains('.datagrid', 'KB');
        self::assertSelectorTextContains('body', 'Pending');
        self::assertSelectorTextContains('body', 'Allocation');
        self::assertSelectorTextContains('body', '0 ms');
        self::assertSelectorTextContains('body', '4');
        self::assertSelectorTextContains('body', '1');
    }

    public function testForeignImportIsNotAccessible(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne([
            'username' => 'owner-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $intruder = UserFactory::createOne([
            'username' => 'intruder-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
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

        $client->loginUser($intruder);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $import->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{0:User,1:int,2:string,3:string} [Owner, ImportId, ImportName, HospitalName]
     */
    private function createImportWithRelations(): array
    {
        $owner = UserFactory::createOne([
            'username' => 'owner-user',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
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
            ->setHospital($hospital)
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
