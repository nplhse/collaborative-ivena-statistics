<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * Template and context of the section an import type extension adds to the import detail page.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by the import detail template.
 */
final readonly class ImportTypeDetailSection
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $template,
        public array $context = [],
    ) {
    }
}
