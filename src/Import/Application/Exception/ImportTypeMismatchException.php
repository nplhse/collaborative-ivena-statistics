<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

use App\Import\Domain\Enum\ImportType;

final class ImportTypeMismatchException extends \RuntimeException
{
    public function __construct(int $importId, ImportType $expected, ?ImportType $actual)
    {
        $actualLabel = $actual instanceof ImportType ? $actual->value : 'missing';
        $hint = $actual instanceof ImportType ? ' Use app:import:start to requeue it.' : '';

        parent::__construct(sprintf(
            'Import #%d is a %s import and cannot be requeued as %s.%s',
            $importId,
            $actualLabel,
            $expected->value,
            $hint,
        ));
    }
}
