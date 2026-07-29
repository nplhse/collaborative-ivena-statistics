<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

final class ImportFilePathOutsideBaseException extends \RuntimeException
{
    public function __construct(string $storedPath = '')
    {
        $message = '' === $storedPath
            ? 'Import file path is empty or invalid'
            : 'Import file path is outside the configured imports base directory';

        parent::__construct($message);
    }
}
