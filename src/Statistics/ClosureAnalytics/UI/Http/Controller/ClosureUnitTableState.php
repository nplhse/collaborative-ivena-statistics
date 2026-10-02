<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use Symfony\Component\HttpFoundation\Request;

final readonly class ClosureUnitTableState
{
    public const string PAGE_PARAM = 'unitsPage';
    public const string LIMIT_PARAM = 'unitsLimit';
    public const string SORT_PARAM = 'unitsSort';
    public const string ORDER_PARAM = 'unitsOrder';
    public const string COLUMNS_PARAM = 'unitsColumns';
    public const string COLUMN_ORDER_PARAM = 'unitsColumnOrder';
    public const string DEFAULT_SORT = 'duration';
    public const string DEFAULT_ORDER = 'desc';
    public const int DEFAULT_LIMIT = 25;

    /** @var list<int> */
    public const array PAGE_SIZES = [25, 50, 100];

    /** @var list<string> */
    public const array SORT_KEYS = ['hospital', 'name', 'share', 'eventCount', 'duration'];

    public function __construct(
        public int $page,
        public int $limit,
        public string $sortBy,
        public string $orderBy,
    ) {
    }

    public static function fromRequest(
        Request $request,
        int $defaultLimit = self::DEFAULT_LIMIT,
        ?string $defaultSort = null,
        ?string $defaultOrder = null,
    ): self {
        $query = $request->query->all();
        $defaultLimit = \in_array($defaultLimit, self::PAGE_SIZES, true) ? $defaultLimit : self::DEFAULT_LIMIT;
        $fallbackSort = \is_string($defaultSort) && \in_array($defaultSort, self::SORT_KEYS, true)
            ? $defaultSort
            : self::DEFAULT_SORT;
        $fallbackOrder = \is_string($defaultOrder) && \in_array($defaultOrder, ['asc', 'desc'], true)
            ? $defaultOrder
            : self::DEFAULT_ORDER;
        $limit = self::integer($query[self::LIMIT_PARAM] ?? null, $defaultLimit);
        if (!\in_array($limit, self::PAGE_SIZES, true)) {
            $limit = $defaultLimit;
        }

        $sortBy = self::string($query[self::SORT_PARAM] ?? null, $fallbackSort);
        if (!\in_array($sortBy, self::SORT_KEYS, true)) {
            $sortBy = $fallbackSort;
        }

        $orderBy = strtolower(self::string($query[self::ORDER_PARAM] ?? null, $fallbackOrder));
        if (!\in_array($orderBy, ['asc', 'desc'], true)) {
            $orderBy = $fallbackOrder;
        }

        return new self(
            max(1, self::integer($query[self::PAGE_PARAM] ?? null, 1)),
            $limit,
            $sortBy,
            $orderBy,
        );
    }

    private static function integer(mixed $value, int $default): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            return $default;
        }

        $filtered = filter_var($value, \FILTER_VALIDATE_INT);

        return false === $filtered ? $default : $filtered;
    }

    private static function string(mixed $value, string $default): string
    {
        return \is_string($value) ? $value : $default;
    }
}
