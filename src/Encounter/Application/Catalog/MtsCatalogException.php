<?php

declare(strict_types=1);

namespace App\Encounter\Application\Catalog;

/**
 * A change to the MTS catalog that MtsCatalogCuration refuses (ADR 032). The message is the translation key
 * `encounter.mts_catalog.error.<reason>` with parameters() in the `encounter` domain.
 */
final class MtsCatalogException extends \DomainException
{
    /** The label gives no code (only punctuation, for example). */
    public const string EMPTY_CODE = 'empty_code';

    /** The code already exists with the same label: a duplicate. */
    public const string DUPLICATE = 'duplicate';

    /** The code already exists with another label: a slug collision, reported to the curator. */
    public const string COLLISION = 'collision';

    /** An alias or merge target that is not an active entry of the same kind. */
    public const string TARGET_NOT_ACTIVE = 'target_not_active';

    public const string MERGE_NOT_PROVISIONAL = 'merge_not_provisional';

    public const string MERGE_INTO_ITSELF = 'merge_into_itself';

    public const string MERGE_HAS_ALIASES = 'merge_has_aliases';

    public const string MERGE_HAS_ASSIGNMENTS = 'merge_has_assignments';

    /** Curated rules of the plausibility table name the diagram (ADR 032, ADR 033). */
    public const string MERGE_HAS_PLAUSIBILITY_RULES = 'merge_has_plausibility_rules';

    public const string NOT_MERGED = 'not_merged';

    /** The alias of a merge is changed or removed only by undoing the merge. */
    public const string MERGE_ALIAS = 'merge_alias';

    public const string ASSIGNMENT_EXISTS = 'assignment_exists';

    public const string ASSIGNMENT_NOT_ACTIVE = 'assignment_not_active';

    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(
        public readonly string $reason,
        private readonly array $parameters = [],
    ) {
        parent::__construct('MTS catalog change refused: '.$reason);
    }

    public function translationKey(): string
    {
        return 'encounter.mts_catalog.error.'.$this->reason;
    }

    /**
     * @return array<string, string|int>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
