<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Exception\ImportException;
use App\Import\Application\Exception\ImportFilePathOutsideBaseException;
use App\Import\Application\Exception\ImportSourceFileNotFoundException;
use App\Import\Domain\Enum\ImportRunReasonCode;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\RetryableException;

/**
 * Decides whether a processing error ends a run or leaves it open for a Messenger retry.
 */
final class ImportRunErrorClassifier
{
    /**
     * Lost connections, deadlocks and lock timeouts are transient: retrying the message can succeed.
     */
    public static function isRetryable(\Throwable $error): bool
    {
        for ($current = $error; $current instanceof \Throwable; $current = $current->getPrevious()) {
            if ($current instanceof RetryableException || $current instanceof ConnectionException) {
                return true;
            }
        }

        return false;
    }

    public static function reasonCodeFor(\Throwable $error): ImportRunReasonCode
    {
        return match (true) {
            $error instanceof ImportFilePathOutsideBaseException => ImportRunReasonCode::InvalidFilePath,
            $error instanceof ImportSourceFileNotFoundException => ImportRunReasonCode::FileNotFound,
            $error instanceof ImportException => ImportRunReasonCode::InvalidFile,
            default => ImportRunReasonCode::ProcessingError,
        };
    }
}
