<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DeleteImportControllerTest extends WebTestCase
{
    use Factories;

    public function testHospitalOwnerCanDeleteImportViaModal(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportForOwner();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseIsSuccessful();

        $client->submit($crawler->filter('#import-delete-modal form')->form());
        self::assertResponseRedirects('/import');

        self::assertNull(self::getContainer()->get(ImportRepository::class)->find($importId));
    }

    public function testForeignParticipantCannotDeleteImport(): void
    {
        $client = self::createClient();
        [, $importId] = $this->createImportForOwner();

        $intruder = UserFactory::createOne([
            'username' => 'delete-intruder-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);

        $client->loginUser($intruder->_real());
        $client->request(
            Request::METHOD_POST,
            '/import/'.$importId.'/delete',
            ['_token' => 'unused-token'],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(self::getContainer()->get(ImportRepository::class)->find($importId));
    }

    public function testDeleteRequiresValidCsrfToken(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportForOwner();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseIsSuccessful();

        $client->request(
            Request::METHOD_POST,
            '/import/'.$importId.'/delete',
            ['_token' => 'invalid-token'],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testRunningImportShowsDisabledDeleteButton(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportForOwner(ImportStatus::RUNNING);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button.btn-outline-danger[disabled]');
        self::assertNotNull(self::getContainer()->get(ImportRepository::class)->find($importId));
    }

    public function testShowPageDisplaysDeleteButtonForOwner(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportForOwner();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#import-delete-modal');
        self::assertSelectorTextContains('body', 'Delete import');
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function createImportForOwner(ImportStatus $status = ImportStatus::COMPLETED): array
    {
        $owner = UserFactory::createOne([
            'username' => 'delete-owner-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $createdBy = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $import = ImportFactory::createOne([
            'name' => 'Delete Me Import',
            'hospital' => $hospital,
            'createdBy' => $createdBy,
            'type' => ImportType::ALLOCATION,
            'status' => $status,
            'filePath' => '/tmp/delete-me.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 12,
            'rowCount' => 1,
            'rowsPassed' => 1,
            'rowsRejected' => 0,
            'runCount' => 1,
            'runTime' => 100,
        ]);

        return [$owner->_real(), (int) $import->getId()];
    }
}
