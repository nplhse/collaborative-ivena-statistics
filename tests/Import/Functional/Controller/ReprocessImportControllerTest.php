<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ReprocessImportControllerTest extends WebTestCase
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

    public function testOwnerReprocessesImportViaModalAndStaysLoggedIn(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImport();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#import-reprocess-modal', 'will be processed again from the stored source file');

        $client->submit($crawler->filter('#import-reprocess-modal form')->form());
        self::assertResponseRedirects('/import/'.$importId.'/processing');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'The import has been queued for reprocessing.');

        // The dispatcher used to clear the token, which logged the user out after this request.
        $client->request(Request::METHOD_GET, '/import/'.$importId);
        self::assertResponseIsSuccessful();

        $runs = self::getContainer()->get(ImportRunRepository::class)->findByImportId($importId);
        self::assertCount(1, $runs);
        self::assertSame(ImportRunTrigger::Reprocess, $runs[0]->getTrigger());
        self::assertSame($owner->getId(), $runs[0]->getInitiatedBy()?->getId());
        self::assertTrue($runs[0]->isFinished(), 'The run is processed synchronously in the test environment.');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $import = $em->find(Import::class, $importId);
        self::assertNotNull($import);
        self::assertSame(2, $import->getRunCount());
        self::assertEquals($runs[0]->getId(), $import->getCurrentRunId());
    }

    public function testForeignParticipantCannotReprocessImport(): void
    {
        $client = self::createClient();
        [, $importId] = $this->createImport();
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);

        $client->loginUser($intruder);
        $client->request(Request::METHOD_POST, '/import/'.$importId.'/reprocess', ['_token' => 'unused-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], self::getContainer()->get(ImportRunRepository::class)->findByImportId($importId));
    }

    public function testReprocessRequiresValidCsrfToken(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImport();

        $client->loginUser($owner);
        $client->request(Request::METHOD_POST, '/import/'.$importId.'/reprocess', ['_token' => 'invalid-token']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], self::getContainer()->get(ImportRunRepository::class)->findByImportId($importId));
    }

    public function testRunningImportShowsDisabledButton(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImport(ImportStatus::RUNNING);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button.btn-outline-primary[disabled][title="This import is currently running and cannot be reprocessed."]');
        self::assertSelectorNotExists('#import-reprocess-modal');
    }

    public function testImportThatStartedRunningMeanwhileIsNotQueued(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImport();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/import/'.$importId);
        $form = $crawler->filter('#import-reprocess-modal form')->form();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement('UPDATE import SET status = ? WHERE id = ?', [ImportStatus::RUNNING->value, $importId]);

        $client->submit($form);
        self::assertResponseRedirects('/import/'.$importId);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'This import is currently running and cannot be reprocessed.');

        self::assertSame([], self::getContainer()->get(ImportRunRepository::class)->findByImportId($importId));
    }

    public function testFailedDispatchShowsAnErrorAndLeavesNoRun(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        // Replaced before the first request initializes the bus; only import messages fail.
        self::getContainer()->set(MessageBusInterface::class, new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof ImportAllocationsMessage) {
                    throw new \RuntimeException('bus down');
                }

                return new Envelope($message, $stamps);
            }
        });
        [$owner, $importId] = $this->createImport();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/import/'.$importId);
        $client->submit($crawler->filter('#import-reprocess-modal form')->form());

        self::assertResponseRedirects('/import/'.$importId);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'The import could not be queued for reprocessing.');

        self::assertSame([], self::getContainer()->get(ImportRunRepository::class)->findByImportId($importId));
    }

    public function testButtonIsHiddenWhenTheCreatorLostTheImportPermission(): void
    {
        $client = self::createClient();
        $owner = $this->participant();
        $creator = $this->participant();
        [, $importId] = $this->createImport(owner: $owner, creator: $creator);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#import-delete-modal');
        self::assertSelectorNotExists('#import-reprocess-modal');
        self::assertSelectorTextNotContains('body', 'Reprocess import');

        $client->request(Request::METHOD_POST, '/import/'.$importId.'/reprocess', ['_token' => 'unused-token']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testButtonIsHiddenWhenTheSourceFileIsGone(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImport(filePath: '/tmp/gone.csv');

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/import/'.$importId);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#import-reprocess-modal');
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function createImport(
        ImportStatus $status = ImportStatus::COMPLETED,
        ?User $owner = null,
        ?User $creator = null,
        ?string $filePath = null,
    ): array {
        $owner ??= $this->participant();
        $creator ??= $owner;
        $state = StateFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);

        $import = ImportFactory::createOne([
            'name' => 'Reprocess Me Import',
            'hospital' => $hospital,
            'createdBy' => $creator,
            'type' => ImportType::ALLOCATION,
            'status' => $status,
            'filePath' => $filePath ?? $this->storedSourceFile(),
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'runCount' => 1,
        ]);

        return [$owner, (int) $import->getId()];
    }

    private function participant(): User
    {
        return UserFactory::createOne([
            'username' => 'reprocess-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
    }

    /**
     * Copies the allocation sample under the imports base directory and returns its stored path.
     */
    private function storedSourceFile(): string
    {
        $importsBaseDir = (string) self::getContainer()->getParameter('app.imports_base_dir');
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $testToken = getenv('TEST_TOKEN') ?: '0';
        $targetDir = $importsBaseDir.'/_tests/'.$testToken.'/'.bin2hex(random_bytes(4));
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            self::fail('Unable to create imports test directory: '.$targetDir);
        }

        $absolutePath = $targetDir.'/allocation_import_sample.csv';
        self::assertTrue(copy(\dirname(__DIR__, 2).'/Fixtures/allocation_import_sample.csv', $absolutePath));
        $this->storedFiles[] = $absolutePath;

        return ltrim(substr($absolutePath, \strlen($projectDir)), '/');
    }
}
