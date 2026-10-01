<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

use App\Import\Domain\Enum\ImportType;

final class ImportTypeMismatchException extends \RuntimeException
{
    public function __construct(int $importId, ImportType $expected, ?ImportType $actual)
    {
        $actualLabel = $actual instanceof ImportType ? $actual->value : 'missing';
        $command = match ($actual) {
            ImportType::ALLOCATION => 'app:import:allocations',
            ImportType::CLOSURE => 'app:import:closures',
            default => null,
        };
        $hint = null !== $command ? sprintf(' Use %s to requeue it.', $command) : '';

        parent::__construct(sprintf(
            'Import #%d is a %s import and cannot be requeued as %s.%s',
            $importId,
            $actualLabel,
            $expected->value,
            $hint,
        ));
    }
}
