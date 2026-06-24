<?php

declare(strict_types=1);

namespace App\Statistics\Application\Report;

use App\Statistics\Application\Report\Exception\UnknownReportDefinitionException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ReportDefinitionRegistry
{
    /** @var array<string, ReportDefinitionInterface> */
    private array $byKey = [];

    /**
     * @param iterable<ReportDefinitionInterface> $definitions
     */
    public function __construct(
        #[AutowireIterator('app.statistics.report_definition')]
        iterable $definitions,
    ) {
        foreach ($definitions as $definition) {
            $this->byKey[$definition->key()] = $definition;
        }
    }

    /**
     * @return list<ReportDefinitionInterface>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function get(string $key): ?ReportDefinitionInterface
    {
        return $this->byKey[$key] ?? null;
    }

    public function getOrFirst(string $key): ReportDefinitionInterface
    {
        if (isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }

        $first = reset($this->byKey);
        if (false === $first) {
            throw new UnknownReportDefinitionException('No report definitions registered.');
        }

        return $first;
    }
}
