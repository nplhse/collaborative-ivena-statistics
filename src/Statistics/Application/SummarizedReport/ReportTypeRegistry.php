<?php

declare(strict_types=1);

namespace App\Statistics\Application\SummarizedReport;

use App\Statistics\Application\SummarizedReport\Exception\UnknownReportTypeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ReportTypeRegistry
{
    /** @var array<string, ReportTypeInterface> */
    private array $byKey = [];

    /**
     * @param iterable<ReportTypeInterface> $types
     */
    public function __construct(
        #[AutowireIterator('app.statistics.report_type')]
        iterable $types,
    ) {
        foreach ($types as $type) {
            $this->byKey[$type->key()] = $type;
        }
    }

    /**
     * @return list<ReportTypeInterface>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function get(string $key): ?ReportTypeInterface
    {
        return $this->byKey[$key] ?? null;
    }

    public function getOrFirst(string $key): ReportTypeInterface
    {
        if (isset($this->byKey[$key])) {
            return $this->byKey[$key];
        }

        $first = reset($this->byKey);
        if (false === $first) {
            throw new UnknownReportTypeException('No report types registered.');
        }

        return $first;
    }
}
