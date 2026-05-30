<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

final class ImportCreatorMissingException extends \RuntimeException
{
    public function __construct(int $importId)
    {
        parent::__construct(sprintf('Import #%d has no createdBy user.', $importId));
    }
}
