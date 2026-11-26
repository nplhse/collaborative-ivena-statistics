<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * One line that an import type extension adds to the import detail page of the admin backend (#1563).
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by ImportCrudController.
 */
final readonly class ImportTypeAdminDetail
{
    /**
     * @param string $value translated plain text; the admin page escapes it
     */
    public function __construct(
        public TranslatableInterface $label,
        public string $value,
    ) {
    }
}
