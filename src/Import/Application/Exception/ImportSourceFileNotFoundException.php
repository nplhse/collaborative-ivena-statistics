<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

final class ImportSourceFileNotFoundException extends \RuntimeException
{
    public function __construct(int $importId)
    {
        parent::__construct(sprintf('No source file found for import %d', $importId));
    }
}
