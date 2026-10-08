<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\UI\Http\Controller;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\Profile\ClosureProfileRef;
use Symfony\Component\HttpFoundation\Request;

final class ClosureAnalyticsFilterRequestResolver
{
    public const string DEPARTMENTS = 'closureDepartments';
    public const string SPECIALITIES = 'closureSpecialities';
    public const string HOSPITALS = 'closureHospitals';
    public const string HOSPITALS_SUBMITTED = 'closureHospitalsSubmitted';
    public const string CARE_LEVELS = 'closureCareLevels';
    public const string REASONS = 'closureReasons';
    public const string CLOSURE_UNITS = 'closureUnits';
    public const string FROM = 'closureFrom';
    public const string TO = 'closureTo';
    public const string EVENT_TYPES = 'closureEventTypes';
    public const string PROFILE = 'closureProfile';

    /** @var list<string> */
    public const array QUERY_KEYS = [
        self::DEPARTMENTS,
        self::SPECIALITIES,
        self::HOSPITALS,
        self::HOSPITALS_SUBMITTED,
        self::CARE_LEVELS,
        self::REASONS,
        self::CLOSURE_UNITS,
        self::FROM,
        self::TO,
        self::EVENT_TYPES,
    ];

    public static function fromRequest(Request $request): ClosureAnalyticsFilter
    {
        [$fromDate, $toDate] = self::dateRange($request);

        return new ClosureAnalyticsFilter(
            self::positiveIds($request, self::DEPARTMENTS),
            self::positiveIds($request, self::SPECIALITIES),
            self::careLevels($request),
            self::reasons($request),
            self::strings($request, self::CLOSURE_UNITS),
            $fromDate,
            $toDate,
            self::eventTypes($request),
            self::positiveIds($request, self::HOSPITALS),
            self::hospitalIdsSubmitted($request),
            ClosureProfileRef::fromQuery($request->query->getString(self::PROFILE)),
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

    private static function hospitalIdsSubmitted(Request $request): bool
    {
        $query = $request->query->all();

        return \array_key_exists(self::HOSPITALS, $query) || \array_key_exists(self::HOSPITALS_SUBMITTED, $query);
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
     * @return list<value-of<ClosureEventType>>
     */
    private static function eventTypes(Request $request): array
    {
        $allowed = array_column(ClosureEventType::cases(), 'value');
        $values = array_filter(
            self::values($request, self::EVENT_TYPES),
            static fn (mixed $value): bool => \is_string($value) && \in_array($value, $allowed, true),
        );

        /* @var list<value-of<ClosureEventType>> $values */
        return array_values(array_unique($values));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function dateRange(Request $request): array
    {
        $from = self::date($request, self::FROM);
        $to = self::date($request, self::TO);
        if (null !== $from && null !== $to && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    private static function date(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $value;
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
