<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

final class UnsupportedImportTypeException extends \RuntimeException
{
    public function __construct(int $importId)
    {
        parent::__construct(sprintf('Import #%d has no type and cannot be started.', $importId));
    }
}
