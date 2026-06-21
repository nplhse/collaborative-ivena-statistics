<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Exception;

final class InvalidExplorerConfigException extends \InvalidArgumentException
{
    /**
     * @param array<string, string|int|float> $parameters
     */
    public function __construct(
        public readonly string $translationKey,
        public readonly array $parameters = [],
    ) {
        parent::__construct($translationKey);
    }
}
