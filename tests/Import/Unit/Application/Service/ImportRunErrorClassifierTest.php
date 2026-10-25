<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Exception\ImportException;
use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
use App\Import\Application\Service\ImportRunErrorClassifier;
use App\Import\Domain\Enum\ImportRunReasonCode;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImportRunErrorClassifierTest extends TestCase
{
    #[DataProvider('retryableProvider')]
    public function testTransientDatabaseErrorsAreRetryable(\Throwable $error): void
    {
        self::assertTrue(ImportRunErrorClassifier::isRetryable($error));
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function retryableProvider(): iterable
    {
        yield 'deadlock' => [new DeadlockException(self::driverError('deadlock detected'), null)];
        yield 'lock timeout' => [new LockWaitTimeoutException(self::driverError('lock timeout'), null)];
        yield 'connection lost' => [new ConnectionLost(self::driverError('server closed the connection'), null)];
        yield 'wrapped deadlock' => [new \RuntimeException('flush failed', 0, new DeadlockException(self::driverError('deadlock detected'), null))];
    }

    #[DataProvider('nonRetryableProvider')]
    public function testOtherErrorsAreNotRetryable(\Throwable $error, ImportRunReasonCode $expectedReason): void
    {
        self::assertFalse(ImportRunErrorClassifier::isRetryable($error));
        self::assertSame($expectedReason, ImportRunErrorClassifier::reasonCodeFor($error));
    }

    /**
     * @return iterable<string, array{\Throwable, ImportRunReasonCode}>
     */
    public static function nonRetryableProvider(): iterable
    {
        yield 'validation' => [new ImportException('hospital mismatch', codeStr: 'HOSPITAL_MISMATCH'), ImportRunReasonCode::InvalidFile];
        yield 'file path' => [new ImportFilePathOutsideBaseException('/etc/passwd'), ImportRunReasonCode::InvalidFilePath];
        yield 'constraint violation' => [new UniqueConstraintViolationException(self::driverError('duplicate key'), null), ImportRunReasonCode::ProcessingError];
        yield 'bug' => [new \TypeError('unexpected null'), ImportRunReasonCode::ProcessingError];
    }

    private static function driverError(string $message): AbstractException
    {
        return new class($message) extends AbstractException {
        };
    }
}
