<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BulkImportActionControllerTest extends WebTestCase
{
    use Factories;

    /** @var list<string> */
    private array $storedFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->storedFiles as $file) {
            @unlink($file);
            @rmdir(\dirname($file));
        }
        $this->storedFiles = [];

        parent::tearDown();
    }

    public function testListOffersReprocessWithACountConfirmationForOwnImports(): void
    {
        $client = self::createClient();
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $own = $this->import($owner, $hospital, 'Own import');
        $creator = $this->participant();
        $this->grant($hospital, $creator, $owner, HospitalPermission::Import);
        $byOther = $this->import($creator, $hospital, 'Import of a colleague');

        $client->loginUser($creator);
        $crawler = $client->request(Request::METHOD_GET, '/import');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="data-table-bulk-select-row"]'), 'only the import the user may change is selectable');
        self::assertSame((string) $byOther->getId(), $crawler->filter('[data-testid="data-table-bulk-select-row"]')->attr('value'));
        self::assertNotSame((string) $own->getId(), $crawler->filter('[data-testid="data-table-bulk-select-row"]')->attr('value'));
        self::assertSame('_top', $crawler->filter('#import-bulk-form')->attr('data-turbo-frame'), 'the result flash is shown outside the list frame');

        $question = $crawler->filter('#import-bulk-confirm-reprocess [data-testid="data-table-bulk-confirm-title"]');
        self::assertSame('Reprocess this import?', $question->attr('data-count-one'));
        self::assertSame('Reprocess these %count% imports?', $question->attr('data-count-other'));
    }

    public function testListWithoutChangeableImportsHasNoSelection(): void
    {
        $client = self::createClient();
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $this->import($owner, $hospital, 'Owner import');
        $viewer = $this->participant();
        $this->grant($hospital, $viewer, $owner, HospitalPermission::Import);

        $client->loginUser($viewer);
        $client->request(Request::METHOD_GET, '/import');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="data-table-bulk"]');
    }

    public function testReprocessQueuesOwnImportsSkipsTheOthersAndShowsTheBatch(): void
    {
        $client = self::createClient();
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $first = $this->import($owner, $hospital, 'First');
        $second = $this->import($owner, $hospital, 'Second');
        $foreignOwner = $this->participant();
        $foreign = $this->import($foreignOwner, $this->hospital($foreignOwner), 'Foreign');

        $client->loginUser($owner);
        $token = $this->bulkToken($client);
        $client->request(Request::METHOD_POST, '/import/bulk', [
            '_token' => $token,
            'bulk_action' => 'reprocess',
            'ids' => [(string) $first->getId(), (string) $foreign->getId(), (string) $second->getId()],
        ]);

        $batchId = (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT MAX(id) FROM import_batch_run');
        self::assertResponseRedirects('/import?batch='.$batchId);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '2 imports were queued for reprocessing.');
        self::assertSelectorTextContains('body', '1 import was skipped: 1 without permission.');
        self::assertSelectorTextContains('[data-testid="import-batch-progress"]', 'Reprocessing of 2 imports finished');
        self::assertCount(2, $client->getCrawler()->filter('[data-testid="data-table-bulk-select-row"]'), 'the list shows only the imports of the batch');

        $runs = self::getContainer()->get(ImportRunRepository::class);
        self::assertSame(ImportRunTrigger::BulkReprocess, $runs->findByImportId((int) $first->getId())[0]->getTrigger());
        self::assertSame([], $runs->findByImportId((int) $foreign->getId()));
    }

    public function testBatchFilterOfAnotherUserShowsNoProgress(): void
    {
        $client = self::createClient();
        $owner = $this->participant();
        $import = $this->import($owner, $this->hospital($owner), 'Mine');
        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, '/import/bulk', [
            '_token' => $this->bulkToken($client),
            'bulk_action' => 'reprocess',
            'ids' => [(string) $import->getId()],
        ]);
        $batchId = (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT MAX(id) FROM import_batch_run');

        $client->loginUser($this->participant());
        $client->request(Request::METHOD_GET, '/import?batch='.$batchId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="import-batch-progress"]', 'Only the imports of one reprocessing are shown.');
    }

    public function testRejectsInvalidTokensUnknownActionsAndEmptySelections(): void
    {
        $client = self::createClient();
        $owner = $this->participant();
        $import = $this->import($owner, $this->hospital($owner), 'Mine');
        $client->loginUser($owner);

        $client->request(Request::METHOD_POST, '/import/bulk', ['_token' => 'invalid', 'bulk_action' => 'reprocess', 'ids' => [(string) $import->getId()]]);
        self::assertResponseStatusCodeSame(403);

        $token = $this->bulkToken($client);
        $client->request(Request::METHOD_POST, '/import/bulk', ['_token' => $token, 'bulk_action' => 'archive', 'ids' => [(string) $import->getId()]]);
        self::assertResponseStatusCodeSame(400);

        $client->request(Request::METHOD_POST, '/import/bulk', ['_token' => $token, 'bulk_action' => 'reprocess']);
        self::assertResponseRedirects('/import');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Please select at least one import.');

        self::assertSame([], self::getContainer()->get(ImportRunRepository::class)->findByImportId((int) $import->getId()));
    }

    private function bulkToken(KernelBrowser $client): string
    {
        $crawler = $client->request(Request::METHOD_GET, '/import');

        return (string) $crawler->filter('#import-bulk-form input[name="_token"]')->attr('value');
    }

    private function participant(): User
    {
        return UserFactory::createOne([
            'username' => 'bulk-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
    }

    private function hospital(User $owner): Hospital
    {
        $state = StateFactory::createOne();

        return HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);
    }

    private function grant(Hospital $hospital, User $user, User $owner, HospitalPermission $permission): void
    {
        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $user,
            'permissions' => HospitalPermissionMask::fromPermissions([$permission]),
            'createdBy' => $owner,
        ]);
    }

    private function import(User $creator, Hospital $hospital, string $name): Import
    {
        return ImportFactory::createOne([
            'name' => $name,
            'hospital' => $hospital,
            'createdBy' => $creator,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::COMPLETED,
            'filePath' => $this->storedSourceFile(),
            'runCount' => 1,
        ]);
    }

    private function storedSourceFile(): string
    {
        $importsBaseDir = (string) self::getContainer()->getParameter('app.imports_base_dir');
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $targetDir = $importsBaseDir.'/_tests/'.(getenv('TEST_TOKEN') ?: '0').'/'.bin2hex(random_bytes(4));
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            self::fail('Unable to create imports test directory: '.$targetDir);
        }

        $absolutePath = $targetDir.'/allocation_import_sample.csv';
        self::assertTrue(copy(\dirname(__DIR__, 2).'/Fixtures/allocation_import_sample.csv', $absolutePath));
        $this->storedFiles[] = $absolutePath;

        return ltrim(substr($absolutePath, \strlen($projectDir)), '/');
    }
}
