<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

final class ImportNotFoundException extends \RuntimeException
{
    public function __construct(int $importId)
    {
        parent::__construct(sprintf('No Import found with ID %d', $importId));
    }
}
