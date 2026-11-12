<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

use App\Import\Domain\Enum\ImportType;

final class UnsupportedImportTypeException extends \RuntimeException
{
    public function __construct(int $importId, ?ImportType $type = null)
    {
        parent::__construct($type instanceof ImportType
            ? sprintf('Import #%d has type "%s", but no workflow dispatcher is registered for it.', $importId, $type->value)
            : sprintf('Import #%d has no type and cannot be started.', $importId));
    }
}
