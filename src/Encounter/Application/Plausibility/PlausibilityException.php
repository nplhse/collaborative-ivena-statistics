<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

/**
 * A change to the plausibility table that PlausibilityCuration, PlausibilitySnapshots or PlausibilityLearner refuses
 * (ADR 033). The message
 * is the translation key `encounter.plausibility.error.<reason>` with parameters() in the `encounter` domain.
 */
final class PlausibilityException extends \DomainException
{
    /** The German name of a problem area gives no code. */
    public const string EMPTY_NAME = 'empty_name';

    /** Another problem area already has the code of this name. */
    public const string AREA_EXISTS = 'area_exists';

    /** Not a three-digit PZC. */
    public const string PZC_INVALID = 'pzc_invalid';

    /** The PZC already belongs to another problem area; each PZC belongs to at most one. */
    public const string PZC_TAKEN = 'pzc_taken';

    /** A rule for a diagram that is not an active entry of the MTS catalog. */
    public const string DIAGRAM_NOT_ACTIVE = 'diagram_not_active';

    /** Points outside 0 … +3. */
    public const string POINTS = 'points';

    public const string SNAPSHOT_NOT_DRAFT = 'snapshot_not_draft';

    public const string SNAPSHOT_NOT_ACTIVE = 'snapshot_not_active';

    /** The learning job found no eligible pair (#1155). */
    public const string NO_ELIGIBLE_PAIRS = 'no_eligible_pairs';

    /**
     * @param array<string, string|int> $parameters
     */
    public function __construct(
        public readonly string $reason,
        private readonly array $parameters = [],
    ) {
        parent::__construct('Plausibility table change refused: '.$reason);
    }

    public function translationKey(): string
    {
        return 'encounter.plausibility.error.'.$this->reason;
    }

    /**
     * @return array<string, string|int>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }
}
