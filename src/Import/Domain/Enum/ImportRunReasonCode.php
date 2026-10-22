<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

/**
 * Stable reason codes for runs that ended as failed. Never store exception messages instead.
 */
enum ImportRunReasonCode: string
{
    case MissingCreator = 'missing_creator';
    case MissingHospital = 'missing_hospital';
    case MissingImportPermission = 'missing_import_permission';
    case MissingClosureRole = 'missing_closure_role';
    case TypeMismatch = 'type_mismatch';
    case MissingFilePath = 'missing_file_path';
    case InvalidFilePath = 'invalid_file_path';
    case FileNotFound = 'file_not_found';
    case InvalidFile = 'invalid_file';
    case NoRows = 'no_rows';
    case RejectionRatioExceeded = 'rejection_ratio_exceeded';
    case ProcessingError = 'processing_error';
    case RetriesExhausted = 'retries_exhausted';
    case DispatchFailed = 'dispatch_failed';

    public function describe(): string
    {
        return match ($this) {
            self::MissingCreator => 'Import has no creator user',
            self::MissingHospital => 'Import has no hospital',
            self::MissingImportPermission => 'Creator has no current import permission',
            self::MissingClosureRole => 'Creator has no closure beta role',
            self::TypeMismatch => 'Import type does not match the processing workflow',
            self::MissingFilePath => 'Import has no file path',
            self::InvalidFilePath => 'Invalid import file path',
            self::FileNotFound => 'Import source file was not found',
            self::InvalidFile => 'Import file failed validation',
            self::NoRows => 'Import file contained no rows',
            self::RejectionRatioExceeded => 'Import exceeded the maximum allowed rejection ratio',
            self::ProcessingError => 'Import processing failed',
            self::RetriesExhausted => 'Import processing failed repeatedly and was given up',
            self::DispatchFailed => 'Import job could not be queued',
        };
    }
}
