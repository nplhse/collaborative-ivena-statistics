<?php

declare(strict_types=1);

namespace App\Tests\Kpi\Unit\Application\Service;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Kpi\Application\Service\ImportFailureReasonResolver;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class ImportFailureReasonResolverTest extends TestCase
{
    private ImportFailureReasonResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ImportFailureReasonResolver();
    }

    public function testNoRowsReason(): void
    {
        $import = $this->createImport(rowCount: 0, rowsRejected: 0);

        self::assertSame('kpi.failure_reason.no_rows', $this->resolver->resolve($import));
    }

    public function testHighRejectionReason(): void
    {
        $import = $this->createImport(rowCount: 100, rowsRejected: 40);

        self::assertSame('kpi.failure_reason.high_rejection', $this->resolver->resolve($import));
    }

    public function testGenericReason(): void
    {
        $import = $this->createImport(rowCount: 100, rowsRejected: 10);

        self::assertSame('kpi.failure_reason.generic', $this->resolver->resolve($import));
    }

    private function createImport(int $rowCount, int $rowsRejected): Import
    {
        $user = new User();
        $user->setUsername('kpi-test-user');
        $user->setEmail('kpi-test@example.com');
        $user->setPassword('test');

        $import = new Import();
        $import
            ->setName('test.csv')
            ->setStatus(ImportStatus::FAILED)
            ->setType(ImportType::ALLOCATION)
            ->setFilePath('/tmp/test.csv')
            ->setFileExtension('.csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(1)
            ->setRowCount($rowCount)
            ->setRowsRejected($rowsRejected)
            ->setRunCount(1)
            ->setRunTime(1)
            ->setCreatedBy($user);

        return $import;
    }
}
