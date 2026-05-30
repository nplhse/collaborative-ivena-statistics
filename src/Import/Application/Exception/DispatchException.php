<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

final class DispatchException extends \RuntimeException
{
    public function __construct(int $importId, \Throwable $previous)
    {
        parent::__construct(
            sprintf('Failed to dispatch import job for Import #%d: %s', $importId, $previous->getMessage()),
            0,
            $previous,
        );
    }
}
