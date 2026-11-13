<?php

declare(strict_types=1);

namespace App\Allocation\Application\CaseFile;

/**
 * One card on the allocation case file, contributed by another context: a Twig template of that
 * context and the variables it needs. The template renders the whole card.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the allocation case file template.
 */
final readonly class AllocationCaseSection
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $key,
        public string $template,
        public array $context = [],
    ) {
    }
}
