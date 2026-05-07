<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisRequestModelFactory
{
    public function __construct(
        private AnalysisFilterFactory $analysisFilterFactory,
    ) {
    }

    public function fromRequest(Request $request): AnalysisRequestModel
    {
        $filterInput = $this->analysisFilterFactory->fromRequest($request);

        return new AnalysisRequestModel(
            $filterInput->analysisKey,
            $filterInput->view,
            $filterInput->chartType,
            $filterInput->dimension,
            $filterInput->chartMeasure,
            $filterInput->rows,
            $filterInput->cols,
            $filterInput->measure,
        );
    }
}
