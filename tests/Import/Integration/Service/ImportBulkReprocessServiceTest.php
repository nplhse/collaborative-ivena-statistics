<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\DTO\ImportBulkSkipReason;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportBulkReprocessService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportBatchRunRepository;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ImportBulkReprocessServiceTest extends DatabaseKernelTestCase
{
    /** @var list<string> */
    private array $storedFiles = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedFiles as $file) {
            @unlink($file);
            @rmdir(\dirname($file));
        }
        $this->storedFiles = [];

        parent::tearDown();
    }

    public function testQueuesEligibleImportsAsOneBatchAndReportsTheOthers(): void
    {
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $first = $this->import($owner, $hospital, 'first');
        $second = $this->import($owner, $hospital, 'second');
        $running = $this->import($owner, $hospital, 'running', ImportStatus::RUNNING);
        $foreign = $this->import($this->participant(), $this->hospital($this->participant()), 'foreign');
        $withoutFile = $this->import($owner, $hospital, 'no file', filePath: 'var/imports/_tests/missing.csv');
        $creator = $this->participant();
        $creatorWithoutGrant = $this->import($creator, $hospital, 'creator without grant');

        $result = $this->service()->reprocess(
            [(int) $first->getId(), (int) $running->getId(), (int) $foreign->getId(), (int) $withoutFile->getId(), (int) $creatorWithoutGrant->getId(), (int) $second->getId(), 2_000_000_000],
            $owner->getId(),
            static fn (Import $import): bool => $import->getHospital()?->getId() === $hospital->getId(),
            fn (Import $import): bool => \in_array($import->getId(), [$first->getId(), $second->getId(), $running->getId()], true),
        );

        self::assertSame(2, $result->handled);
        self::assertSame([
            ImportBulkSkipReason::Busy->value => 1,
            ImportBulkSkipReason::Forbidden->value => 2,
            ImportBulkSkipReason::SourceMissing->value => 1,
            ImportBulkSkipReason::CreatorPermission->value => 1,
        ], $result->skipped);
        self::assertNotNull($result->batchRunId);

        $batch = self::getContainer()->get(ImportBatchRunRepository::class)->find($result->batchRunId);
        self::assertInstanceOf(ImportBatchRun::class, $batch);
        self::assertSame(ImportBatchRun::SOURCE_UI, $batch->getOptions()['source']);
        self::assertSame($owner->getId(), $batch->getInitiatedByUserId());
        self::assertNotNull($batch->getDispatchClosedAt());

        foreach ([$first, $second] as $import) {
            $runs = self::getContainer()->get(ImportRunRepository::class)->findByImportId((int) $import->getId());
            self::assertCount(1, $runs);
            self::assertSame(ImportRunTrigger::BulkReprocess, $runs[0]->getTrigger());
            self::assertTrue($runs[0]->isFinished(), 'processed synchronously in the test environment');
        }
        self::assertSame([], self::getContainer()->get(ImportRunRepository::class)->findByImportId((int) $running->getId()), 'a running import is not queued again');

        // Both runs finished during the dispatch phase; closing it finished the batch with one summary and no
        // notification per run. The sample rows do not match the test hospital, so both runs fail on the
        // rejection ratio and the summary is the high-priority variant.
        self::assertSame(['import.batch_failed'], $this->notificationTypes($owner));
        self::assertSame('{"batch":'.$result->batchRunId.'}', $this->connection()->fetchOne(
            "SELECT action_parameters FROM notification WHERE recipient_id = ? AND type = 'import.batch_failed'",
            [$owner->getId()],
        ));
    }

    public function testNothingEligibleCreatesNoBatch(): void
    {
        $owner = $this->participant();
        $import = $this->import($owner, $this->hospital($owner), 'busy', ImportStatus::PENDING);

        $result = $this->service()->reprocess([(int) $import->getId()], $owner->getId(), static fn (): bool => true, static fn (): bool => true);

        self::assertSame(0, $result->handled);
        self::assertSame([ImportBulkSkipReason::Busy->value => 1], $result->skipped);
        self::assertNull($result->batchRunId);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM import_batch_run'));
    }

    public function testAFailedDispatchOnlySkipsThatImport(): void
    {
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $queued = $this->import($owner, $hospital, 'queued');
        $failing = $this->import($owner, $hospital, 'failing');
        $failingId = (int) $failing->getId();
        self::getContainer()->set(MessageBusInterface::class, new readonly class($failingId) implements MessageBusInterface {
            public function __construct(private int $failingId)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof ImportAllocationsMessage && $message->importId === $this->failingId) {
                    throw new \RuntimeException('bus down');
                }

                return new Envelope($message, $stamps);
            }
        });

        $result = $this->service()->reprocess([$failingId, (int) $queued->getId()], $owner->getId(), static fn (): bool => true, static fn (): bool => true);

        self::assertSame(1, $result->handled);
        self::assertSame([ImportBulkSkipReason::DispatchFailed->value => 1], $result->skipped);
        self::assertSame(
            ['dispatch_failed', 'queued'],
            $this->connection()->fetchFirstColumn('SELECT status FROM import_batch_run_item WHERE run_id = ? ORDER BY id', [$result->batchRunId]),
        );
        self::assertNull(
            $this->connection()->fetchOne('SELECT processing_finished_at FROM import_batch_run WHERE id = ?', [$result->batchRunId]),
            'the queued run is still open, so the batch is not finished',
        );
    }

    public function testUiBatchesAreNotResumedFromTheConsole(): void
    {
        $repository = self::getContainer()->get(ImportBatchRunRepository::class);
        $console = new ImportBatchRun(['initiatedByUserId' => null]);
        $ui = new ImportBatchRun(['source' => ImportBatchRun::SOURCE_UI]);
        $repository->save($console);
        $repository->save($ui);
        $repository->flush();

        self::assertSame($console->getId(), $repository->findLatestIncomplete()?->getId());
    }

    private function service(): ImportBulkReprocessService
    {
        return self::getContainer()->get(ImportBulkReprocessService::class);
    }

    private function participant(): User
    {
        return UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
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

    private function import(User $creator, Hospital $hospital, string $name, ImportStatus $status = ImportStatus::COMPLETED, ?string $filePath = null): Import
    {
        return ImportFactory::createOne([
            'name' => $name,
            'hospital' => $hospital,
            'createdBy' => $creator,
            'type' => ImportType::ALLOCATION,
            'status' => $status,
            'filePath' => $filePath ?? $this->storedSourceFile(),
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

    /**
     * @return list<string>
     */
    private function notificationTypes(User $user): array
    {
        return array_map(strval(...), $this->connection()->fetchFirstColumn('SELECT type FROM notification WHERE recipient_id = ? ORDER BY type', [$user->getId()]));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
