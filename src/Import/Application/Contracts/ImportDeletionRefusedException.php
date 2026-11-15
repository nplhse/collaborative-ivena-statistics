<?php

declare(strict_types=1);

namespace App\Import\Application\Contracts;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Thrown by an ImportDataRemoverInterface when the import must not be deleted or cleaned up, before
 * anything is removed or while the removal transaction can still be rolled back. The deletion stops,
 * the import stays complete, and $reason tells the user why (in the domain of the refusing context).
 */
final class ImportDeletionRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly TranslatableInterface $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
