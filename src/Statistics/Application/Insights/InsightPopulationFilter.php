<?php

declare(strict_types=1);

namespace App\Statistics\Application\Insights;

/**
 * Describes how to select an allocation population on allocation_stats_projection.
 */
final readonly class InsightPopulationFilter
{
    /** @var list<string> */
    public const array ALLOWED_COLUMNS = [
        'indication_normalized_id',
        'speciality_id',
        'assignment_id',
        'department_id',
        'occasion_id',
        'infection_id',
        'secondary_transport_id',
    ];

    /**
     * @param list<int> $ids
     */
    public function __construct(
        public string $column,
        public array $ids,
    ) {
        if (!\in_array($column, self::ALLOWED_COLUMNS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported insight projection column "%s".', $column));
        }
    }

    /**
     * @param list<int> $ids
     */
    public static function of(string $column, array $ids): self
    {
        return new self($column, array_values(array_unique(array_map(static fn (int $id): int => $id, $ids))));
    }

    /**
     * @param list<int> $ids
     */
    public static function indications(array $ids): self
    {
        return self::of('indication_normalized_id', $ids);
    }

    public function isEmpty(): bool
    {
        return [] === $this->ids;
    }

    public function sqlInPredicate(string $paramName = 'subject_ids', string $tableAlias = ''): string
    {
        $column = '' === $tableAlias ? $this->column : $tableAlias.'.'.$this->column;

        return sprintf('%s IN (:%s)', $column, $paramName);
    }

    public function sqlBaselinePredicate(string $paramName = 'subject_ids'): string
    {
        return sprintf('(%s IS NULL OR %s NOT IN (:%s))', $this->column, $this->column, $paramName);
    }
}
