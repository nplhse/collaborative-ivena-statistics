<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ImportStatusControllerTest extends WebTestCase
{
    use Factories;

    public function testStatusEndpointReturnsExpectedJsonShape(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportForOwner(ImportStatus::PENDING);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d/status', $importId));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        /** @var array<string, mixed> $data */
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($importId, $data['id']);
        self::assertSame('pending', $data['status']);
        self::assertIsString($data['label']);
        self::assertIsString($data['message']);
        self::assertArrayHasKey('progress', $data);
        self::assertFalse($data['isFinal']);
        self::assertStringContainsString(\sprintf('/import/%d', $importId), (string) $data['detailUrl']);
        self::assertSame('tabler:clock', $data['icon']);
        self::assertSame('secondary', $data['iconTone']);
        self::assertSame('green', $data['stepsModifier']);
        self::assertIsArray($data['steps']);
        self::assertCount(3, $data['steps']);
        self::assertSame('active', $data['steps'][1]['state']);
    }

    public function testStatusEndpointReturnsCompletedStepStates(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportForOwner(ImportStatus::COMPLETED);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d/status', $importId));

        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($data['isFinal']);
        self::assertSame('done', $data['steps'][0]['state']);
        self::assertSame('done', $data['steps'][1]['state']);
        self::assertSame('active', $data['steps'][2]['state']);
        self::assertSame('Finished', $data['steps'][2]['label']);
    }

    #[DataProvider('isFinalStatusProvider')]
    public function testStatusEndpointReportsIsFinalCorrectly(ImportStatus $status, bool $expectedIsFinal): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportForOwner($status);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d/status', $importId));

        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $data */
        $data = json_decode($client->getResponse()->getContent() ?: '', true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(strtolower($status->name), $data['status']);
        self::assertSame($expectedIsFinal, $data['isFinal']);
    }

    public function testForeignImportStatusIsForbidden(): void
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
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ]);

        $client->loginUser($intruder->_real());
        $client->request(Request::METHOD_GET, \sprintf('/import/%d/status', $import->getId()));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array<string, array{status: ImportStatus, expectedIsFinal: bool}>
     */
    public static function isFinalStatusProvider(): array
    {
        return [
            'PENDING' => ['status' => ImportStatus::PENDING, 'expectedIsFinal' => false],
            'RUNNING' => ['status' => ImportStatus::RUNNING, 'expectedIsFinal' => false],
            'COMPLETED' => ['status' => ImportStatus::COMPLETED, 'expectedIsFinal' => true],
            'PARTIAL' => ['status' => ImportStatus::PARTIAL, 'expectedIsFinal' => true],
            'FAILED' => ['status' => ImportStatus::FAILED, 'expectedIsFinal' => true],
            'CANCELLED' => ['status' => ImportStatus::CANCELLED, 'expectedIsFinal' => true],
        ];
    }

    /**
     * @return array{0:User,1:int}
     */
    private function createImportForOwner(ImportStatus $status): array
    {
        $owner = UserFactory::createOne([
            'username' => 'owner-'.bin2hex(random_bytes(4)),
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
            'name' => 'Status Test Import',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => $status,
            'filePath' => '/tmp/status-test.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 100,
            'rowCount' => 10,
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ]);

        return [$owner->_real(), (int) $import->getId()];
    }
}
