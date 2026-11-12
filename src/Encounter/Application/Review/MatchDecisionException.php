<?php

declare(strict_types=1);

namespace App\Encounter\Application\Review;

/**
 * A review decision that cannot be applied as asked (#1054). Nothing has been written.
 */
final class MatchDecisionException extends \RuntimeException
{
    /** The allocation or visit does not belong to the hospital. */
    public const string NOT_FOUND = 'not_found';

    /** The visit is linked to another allocation; only a swap moves it. */
    public const string VISIT_LINKED = 'visit_linked';

    /** The visit lies outside the allocation's review window. */
    public const string NOT_A_CANDIDATE = 'not_a_candidate';

    /** The pair already has this decision. */
    public const string UNCHANGED = 'unchanged';

    /** The swap is no longer proposed (links changed in the meantime). */
    public const string SWAP_OUTDATED = 'swap_outdated';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('Match decision refused: %s.', $reason));
    }

    public function translationKey(): string
    {
        return 'encounter.review.error.'.$this->reason;
    }
}
