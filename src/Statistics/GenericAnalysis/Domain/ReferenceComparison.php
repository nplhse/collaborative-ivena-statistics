<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Domain;

/**
 * Comparison of reference hospitals with the other hospitals of the scope (#1346): a synthetic series on the
 * allocations source with one bucket per reference hospital (its id as string) and one pooled bucket for the rest.
 */
final class ReferenceComparison
{
    public const string DIMENSION_KEY = 'reference_group';

    public const string OTHERS_BUCKET = 'others';

    /** Bound parameter of the reference hospital ids in the series expression. */
    public const string PARAMETER = 'reference_hospitals';

    public const int MAX_REFERENCES = 5;
}
