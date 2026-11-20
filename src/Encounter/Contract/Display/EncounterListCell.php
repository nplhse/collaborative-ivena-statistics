<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

/**
 * One cell of a schema column in the Explore allocation list: a Twig template of the schema (for a bundle
 * `@<Bundle>/…`) and its variables. The template renders inline content.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the list cell template.
 */
final readonly class EncounterListCell
{
    /**
     * @param array<string, mixed> $context
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function __construct(
        public string $template,
        public array $context = [],
    ) {
    }
}
