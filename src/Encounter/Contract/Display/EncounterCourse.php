<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

/**
 * The course a schema shows at the top of the hospital course card for one encounter (ADR 037, #1300): a Twig
 * template of the schema and its variables, rendered as the card's first block. Without a template the schema
 * has a record of the encounter but nothing to show (for example no effective link of its own).
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the hospital course card template.
 */
final readonly class EncounterCourse
{
    /**
     * @param array<string, mixed> $context
     *
     * @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it.
     */
    public function __construct(
        public ?string $template = null,
        public array $context = [],
    ) {
    }
}
