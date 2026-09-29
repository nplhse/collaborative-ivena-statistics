<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use Symfony\Component\HttpFoundation\Request;

final class ClosureAnalyticsFilterRequestResolver
{
    public const string DEPARTMENTS = 'closureDepartments';
    public const string SPECIALITIES = 'closureSpecialities';
    public const string CARE_LEVELS = 'closureCareLevels';
    public const string REASONS = 'closureReasons';
    public const string CLOSURE_UNITS = 'closureUnits';

    /** @var list<string> */
    public const array QUERY_KEYS = [
        self::DEPARTMENTS,
        self::SPECIALITIES,
        self::CARE_LEVELS,
        self::REASONS,
        self::CLOSURE_UNITS,
    ];

    public static function fromRequest(Request $request): ClosureAnalyticsFilter
    {
        return new ClosureAnalyticsFilter(
            self::positiveIds($request, self::DEPARTMENTS),
            self::positiveIds($request, self::SPECIALITIES),
            self::careLevels($request),
            self::reasons($request),
            self::strings($request, self::CLOSURE_UNITS),
        );
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public static function withoutFilters(array $query): array
    {
        foreach (self::QUERY_KEYS as $key) {
            unset($query[$key]);
        }

        return $query;
    }

    /**
     * @return list<int>
     */
    private static function positiveIds(Request $request, string $key): array
    {
        $ids = [];
        foreach (self::values($request, $key) as $value) {
            if ((\is_int($value) || \is_string($value)) && ctype_digit((string) $value)) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<value-of<ClosureCareLevel>>
     */
    private static function careLevels(Request $request): array
    {
        $allowed = array_column(ClosureCareLevel::cases(), 'value');
        $values = array_filter(
            self::values($request, self::CARE_LEVELS),
            static fn (mixed $value): bool => \is_string($value) && \in_array($value, $allowed, true),
        );

        /* @var list<value-of<ClosureCareLevel>> $values */
        return array_values(array_unique($values));
    }

    /**
     * @return list<value-of<ClosureReason>>
     */
    private static function reasons(Request $request): array
    {
        $allowed = array_column(ClosureReason::cases(), 'value');
        $values = array_filter(
            self::values($request, self::REASONS),
            static fn (mixed $value): bool => \is_string($value) && \in_array($value, $allowed, true),
        );

        /* @var list<value-of<ClosureReason>> $values */
        return array_values(array_unique($values));
    }

    /**
     * @return list<string>
     */
    private static function strings(Request $request, string $key): array
    {
        $values = [];
        foreach (self::values($request, $key) as $value) {
            if (!\is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ('' !== $value && mb_strlen($value) <= 255) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<mixed>
     */
    private static function values(Request $request, string $key): array
    {
        $value = $request->query->all()[$key] ?? [];

        return \is_array($value) ? array_values($value) : [$value];
    }
}
