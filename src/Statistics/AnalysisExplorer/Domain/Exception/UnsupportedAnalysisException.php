<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Exception;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;

final class UnsupportedAnalysisException extends \RuntimeException
{
    public static function forDataSource(AnalysisDataSourceKey $dataSourceKey): self
    {
        return new self(sprintf('No analysis runner supports data source "%s".', $dataSourceKey->value));
    }
}
