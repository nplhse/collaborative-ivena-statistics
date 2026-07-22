<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assessment;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Domain\Enum\ImportStatus;
use Symfony\Component\Messenger\MessageBusInterface;

final class ImportAllocationsMessageHandlerMessageBusTest extends ImportAllocationsMessageHandlerTestCase
{
    public function testDispatchViaMessageBusRunsImportWithSuppressedAllocationAudits(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();

        $allocationAuditsBeforeInvoke = $this->countAuditEntriesForEntityClass(Allocation::class);

        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch(new ImportAllocationsMessage($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }

        self::assertSame(
            $allocationAuditsBeforeInvoke,
            $this->countAuditEntriesForEntityClass(Allocation::class),
            'Import via MessageBus must suppress per-row Allocation audit entries (middleware + handler path)',
        );
        self::assertTrue(
            $this->importEntityHasAuditIntent($id, 'import.run.finished'),
            'Expected Import audit metadata to include import.run.finished intent for this import id',
        );

        $fresh = $this->imports->find($id);
        self::assertNotNull($fresh);
        self::assertNotSame(ImportStatus::FAILED, $fresh->getStatus());
    }

    public function testDispatchViaMessageBusRunsImportWithSuppressedAssessmentAudits(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport(withAssessment: true);

        $assessmentAuditsBeforeInvoke = $this->countAuditEntriesForEntityClass(Assessment::class);

        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch(new ImportAllocationsMessage($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }

        self::assertSame(
            $assessmentAuditsBeforeInvoke,
            $this->countAuditEntriesForEntityClass(Assessment::class),
            'Import via MessageBus must suppress per-row Assessment audit entries (middleware + handler path)',
        );
        self::assertGreaterThan(0, $this->countAssessmentsForImport($id));
    }

    public function testDispatchViaMessageBusRunsAllocationImportSampleFixture(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeAllocationImportSampleCsvImport();

        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch(new ImportAllocationsMessage($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }

        $fresh = $this->imports->find($id);
        self::assertNotNull($fresh);
        self::assertTrue(
            $fresh->isFinalStatus(),
            sprintf(
                'Expected final import status, got %s',
                null !== ($status = $fresh->getStatus()) ? $status->value : 'null',
            ),
        );
    }
}
