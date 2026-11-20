<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

/**
 * What a schema shows of one encounter in a list cell: a Twig template of the schema (for a bundle `@<Bundle>/…`)
 * and its variables. The template renders inline content, no card.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the list detail template.
 */
final readonly class EncounterListDetail
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
