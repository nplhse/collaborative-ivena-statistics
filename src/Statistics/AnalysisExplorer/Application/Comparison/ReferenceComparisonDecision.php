<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\Comparison;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;

/**
 * The configuration effective for one viewer (#1346). When the stored comparison is not available, $config has
 * no references and no share within series, and $dropped is true; the stored configuration itself is not touched.
 */
final readonly class ReferenceComparisonDecision
{
    public const string NOTICE_KEY = 'stats.analysis_explorer.reference_comparison.unavailable';

    public function __construct(
        public AnalysisViewConfig $config,
        public bool $dropped = false,
    ) {
    }
}
