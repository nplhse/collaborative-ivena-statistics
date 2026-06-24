<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\Contract;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery as GenericAnalysisQuery;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.analysis_explorer.query_mapper')]
interface ExplorerAnalysisQueryMapperInterface
{
    public function supports(AnalysisQuery $query): bool;

    public function map(AnalysisQuery $query): GenericAnalysisQuery;
}
