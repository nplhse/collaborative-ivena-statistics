<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use Symfony\Component\HttpFoundation\Request;

final readonly class ClosureEventTableState
{
    public const string DEFAULT_SORT = 'startsAt';
    public const string DEFAULT_ORDER = 'desc';
    public const int DEFAULT_LIMIT = 25;
    public const string DEFAULT_VIEW = 'events';

    /** @var list<int> */
    public const array PAGE_SIZES = [25, 50, 100];

    /** @var list<string> */
    public const array EVENT_SORT_KEYS = [
        'startsAt',
        'endsAt',
        'hospital',
        'event',
        'closureCount',
        'summedMinutes',
        'actualMinutes',
    ];

    /** @var list<string> */
    public const array INTERVAL_SORT_KEYS = [
        'startsAt',
        'endsAt',
        'hospital',
        'speciality',
        'department',
        'careLevel',
        'reason',
        'closureUnit',
        'durationMinutes',
    ];

    public function __construct(
        public int $page,
        public int $limit,
        public string $sortBy,
        public string $orderBy,
        public string $view,
    ) {
    }

    public static function fromRequest(Request $request, int $defaultLimit = self::DEFAULT_LIMIT): self
    {
        $query = $request->query->all();
        $defaultLimit = \in_array($defaultLimit, self::PAGE_SIZES, true) ? $defaultLimit : self::DEFAULT_LIMIT;
        $limit = self::integer($query['limit'] ?? null, $defaultLimit);
        if (!\in_array($limit, self::PAGE_SIZES, true)) {
            $limit = $defaultLimit;
        }

        $view = self::string($query['tableView'] ?? null, self::DEFAULT_VIEW);
        if (!\in_array($view, ['events', 'intervals'], true)) {
            $view = self::DEFAULT_VIEW;
        }

        $sortBy = self::string($query['sortBy'] ?? null, self::DEFAULT_SORT);
        $sortKeys = 'intervals' === $view ? self::INTERVAL_SORT_KEYS : self::EVENT_SORT_KEYS;
        if (!\in_array($sortBy, $sortKeys, true)) {
            $sortBy = self::DEFAULT_SORT;
        }

        $orderBy = strtolower(self::string($query['orderBy'] ?? null, self::DEFAULT_ORDER));
        if (!\in_array($orderBy, ['asc', 'desc'], true)) {
            $orderBy = self::DEFAULT_ORDER;
        }

        return new self(
            max(1, self::integer($query['page'] ?? null, 1)),
            $limit,
            $sortBy,
            $orderBy,
            $view,
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
