<?php

declare(strict_types=1);

namespace App\Storage\Application\Contract;

/**
 * Size of the application database as reported by the database itself (ADR 017, "Usage metric").
 */
interface DatabaseSizeReaderInterface
{
    /**
     * @throws \RuntimeException when the size cannot be determined
     */
    public function bytes(): int;
}
