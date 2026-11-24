<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Service\ImportSourceDownloadAuditLogger;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
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

        $auditEntry = $this->findLatestDownloadAudit((string) $importId);
        self::assertNotNull($auditEntry);
        self::assertSame($admin->getId(), $auditEntry->getActor()?->getId());
        self::assertSame(ImportSourceDownloadAuditLogger::ACTION, $auditEntry->getAction());
        self::assertSame(Import::class, $auditEntry->getEntityClass());
        self::assertSame(ImportSourceDownloadAuditLogger::INTENT, $auditEntry->getMetadata()['intent'] ?? null);
        self::assertSame($importId, $auditEntry->getMetadata()['import_id'] ?? null);
    }

    public function testHospitalOwnerCanDownloadSourceFileAndTheDownloadIsAudited(): void
    {
        $client = self::createClient();
        [$owner, $importId, , $content] = $this->createImportWithSourceFile();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(\strlen($content), (int) $client->getResponse()->headers->get('Content-Length'));

        $auditEntry = $this->findLatestDownloadAudit((string) $importId);
        self::assertNotNull($auditEntry);
        self::assertSame($owner->getId(), $auditEntry->getActor()?->getId());
        self::assertSame($importId, $auditEntry->getMetadata()['import_id'] ?? null);
    }

    public function testCreatorWithImportGrantButWithoutOwnerRoleCannotDownloadSourceFile(): void
    {
        $client = self::createClient();
        [, $importId, , , $creator] = $this->createImportWithSourceFile(creatorGrant: true);

        $client->loginUser($creator);
        $client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->findLatestDownloadAudit((string) $importId));
    }

    public function testOwnerOfAnotherHospitalCannotDownloadSourceFile(): void
    {
        $client = self::createClient();
        [, $importId] = $this->createImportWithSourceFile();
        [$otherOwner] = $this->createImportWithSourceFile();

        $client->loginUser($otherOwner);
        $client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');

        self::assertResponseStatusCodeSame(403);
    }

    public function testMissingSourceFileRedirectsAdminToTheImportWithAMessage(): void
    {
        $this->assertMissingFileRedirects(asAdmin: true);
    }

    public function testMissingSourceFileRedirectsOwnerToTheImportWithAMessage(): void
    {
        $this->assertMissingFileRedirects(asAdmin: false);
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

    public function testShowPageDisplaysDownloadLinkForHospitalOwner(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportWithSourceFile();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/import/'.$importId.'/source-file"]');
        self::assertSelectorNotExists('[data-testid="import-source-file-missing"]');
    }

    public function testShowPageSaysTheFileIsGoneInsteadOfOfferingTheDownload(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportWithSourceFile(writeFile: false);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/import/'.$importId.'/source-file"]');
        self::assertSelectorTextContains('[data-testid="import-source-file-missing"]', 'The original file of this import is no longer stored.');
    }

    public function testShowPageHidesDownloadLinkForCreatorWithImportGrant(): void
    {
        $client = self::createClient();
        [, $importId, , , $creator] = $this->createImportWithSourceFile(creatorGrant: true);

        $client->loginUser($creator);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/import/'.$importId.'/source-file"]');
        self::assertSelectorNotExists('[data-testid="import-source-file-missing"]');
    }

    private function assertMissingFileRedirects(bool $asAdmin): void
    {
        $client = self::createClient();
        [$user, $importId] = $this->createImportWithSourceFile(asAdmin: $asAdmin, writeFile: false);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/import/'.$importId.'/source-file');

        self::assertResponseRedirects('/import/'.$importId);
        $client->followRedirect();
        self::assertSelectorTextContains('.alert', 'The original file of this import is no longer stored.');
        self::assertNull($this->findLatestDownloadAudit((string) $importId));
    }

    /**
     * @return array{0: User, 1: int, 2: string, 3: string, 4: User}
     */
    private function createImportWithSourceFile(bool $asAdmin = false, bool $writeFile = true, bool $creatorGrant = false): array
    {
        $owner = UserFactory::createOne([
            'username' => 'owner-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $admin = $asAdmin ? UserFactory::new()->asAdmin()->create() : $owner;
        $createdBy = UserFactory::createOne([
            'username' => 'creator-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
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

        if ($creatorGrant) {
            HospitalAccessGrantFactory::createOne([
                'hospital' => $hospital,
                'user' => $createdBy,
                'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Import]),
                'createdBy' => $owner,
            ]);
        }

        return [$admin, (int) $import->getId(), $relativePath, $content, $createdBy];
    }

    private function findLatestDownloadAudit(string $importId): ?AuditEntry
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var list<AuditEntry> $entries */
        $entries = $em->getRepository(AuditEntry::class)->findBy(
            [
                'action' => ImportSourceDownloadAuditLogger::ACTION,
                'entityClass' => Import::class,
                'entityId' => $importId,
            ],
            ['id' => 'DESC'],
            1,
        );

        return $entries[0] ?? null;
    }
}
