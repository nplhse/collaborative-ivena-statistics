<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Display;

/**
 * A column of a schema in the Explore allocation list. The key is unique across schemas (lowercase letters and
 * underscores, prefixed with the schema, for example `ed_visit_triage_category`); the label is a translation key of
 * `domain`.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the encounter list extension.
 */
final readonly class EncounterListColumn
{
    /** @psalm-suppress PossiblyUnusedMethod Contract for encounter schema plugins (ADR 037); plugins and tests call it. */
    public function __construct(
        public string $key,
        public string $label,
        public string $domain,
    ) {
        if (1 !== preg_match('/^[a-z][a-z_]*$/', $key)) {
            throw new \InvalidArgumentException(\sprintf('Invalid encounter list column key "%s".', $key));
        }
    }
}
