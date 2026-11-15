<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

/**
 * What a schema shows of its records of one encounter: a Twig template of the schema (for a bundle
 * `@<Bundle>/…`) and its variables. The template renders a block inside a card of the core.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the hospital course card template.
 */
final readonly class RecordSection
{
    /**
     * @param array<string, mixed> $context
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function __construct(
        public string $key,
        public string $template,
        public array $context = [],
    ) {
    }
}
