<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Import\Application\Message\ImportAllocationsMessage;

final class ImportAllocationsMessageHandlerReimportTest extends ImportAllocationsMessageHandlerTestCase
{
    public function testReimportWithAssessmentSucceedsAfterCleanup(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport(withAssessment: true);

        try {
            $this->handler->__invoke(new ImportAllocationsMessage($id));

            self::assertSame(1, $this->countAllocationsForImport($id));
            self::assertGreaterThan(0, $this->countAssessmentsForImport($id));

            $this->handler->__invoke(new ImportAllocationsMessage($id));

            self::assertSame(1, $this->countAllocationsForImport($id));
            self::assertGreaterThan(0, $this->countAssessmentsForImport($id));
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }
    }

    public function testReimportDeletesPreviousAllocationsBeforeImportingAgain(): void
    {
        ['id' => $id, 'csvPath' => $csvPath] = $this->arrangeSingleRowSuccessfulCsvImport();

        try {
            $this->handler->__invoke(new ImportAllocationsMessage($id));

            self::assertSame(1, $this->countAllocationsForImport($id));

            $this->handler->__invoke(new ImportAllocationsMessage($id));

            self::assertSame(1, $this->countAllocationsForImport($id));

            $fresh = $this->imports->find($id);
            self::assertNotNull($fresh);
            self::assertSame(2, $fresh->getRunCount());
        } finally {
            if (is_file($csvPath)) {
                @unlink($csvPath);
            }
        }
    }
}
