<?php

declare(strict_types=1);

namespace App\Import\Application\Exception;

final class ImportRequeueInterruptedException extends \RuntimeException
{
    public function __construct(int $signal)
    {
        parent::__construct(sprintf('Import requeue batch interrupted by signal %d.', $signal));
    }
}
