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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DownloadImportSourceFileControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanDownloadSourceFile(): void
    {
        $client = self::createClient();
        [$admin, $importId, $relativePath, $content] = $this->createImportWithSourceFile(asAdmin: true);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('Test Allocations.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(\strlen($content), (int) $client->getResponse()->headers->get('Content-Length'));

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertSame($content, file_get_contents(Path::join((string) $projectDir, $relativePath)));
    }

    public function testHospitalOwnerWithoutAdminRoleCannotDownloadSourceFile(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportWithSourceFile();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGetsNotFoundWhenSourceFileMissingOnDisk(): void
    {
        $client = self::createClient();
        [$admin, $importId] = $this->createImportWithSourceFile(asAdmin: true, writeFile: false);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');

        self::assertResponseStatusCodeSame(404);
    }

    public function testShowPageDisplaysDownloadLinkForAdmin(): void
    {
        $client = self::createClient();
        [$admin, $importId] = $this->createImportWithSourceFile(asAdmin: true);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/import/'.$importId.'/source-file"]');
        self::assertSelectorTextContains('a[href="/import/'.$importId.'/source-file"]', 'Download original file');
    }

    public function testShowPageHidesDownloadLinkForParticipant(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportWithSourceFile();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/import/'.$importId.'/source-file"]');
    }

    /**
     * @return array{0: User, 1: int, 2: string, 3: string}
     */
    private function createImportWithSourceFile(bool $asAdmin = false, bool $writeFile = true): array
    {
        $owner = UserFactory::createOne([
            'username' => 'owner-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $admin = $asAdmin ? UserFactory::new()->asAdmin()->create() : $owner;
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $relativePath = 'var/imports/functional/'.bin2hex(random_bytes(4)).'.csv';
        $absolutePath = Path::join((string) $projectDir, $relativePath);
        $content = "col1;col2\nvalue1;value2\n";

        if ($writeFile) {
            new Filesystem()->dumpFile($absolutePath, $content);
        }

        $import = ImportFactory::createOne([
            'name' => 'Test Allocations',
            'hospital' => $hospital,
            'createdBy' => $createdBy,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => $relativePath,
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => \strlen($content),
            'rowCount' => 1,
            'rowsPassed' => 1,
            'rowsRejected' => 0,
            'runCount' => 0,
            'runTime' => 0,
        ]);

        return [$admin, (int) $import->getId(), $relativePath, $content];
    }
}
