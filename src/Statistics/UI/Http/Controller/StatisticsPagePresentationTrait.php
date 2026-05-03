<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shared headings and query URL assembly for statistics pages (overview, analysis, reports).
 */
trait StatisticsPagePresentationTrait
{
    abstract protected function getTranslator(): TranslatorInterface;

    abstract protected function getStatisticsNavigationUrlBuilder(): StatisticsNavigationUrlBuilder;

    /**
     * @param array<string, scalar|null> $replace
     * @param list<string>               $removeKeys
     */
    protected function statisticsPageUrl(
        Request $request,
        string $routeName,
        array $replace = [],
        array $removeKeys = [],
    ): string {
        return $this->getStatisticsNavigationUrlBuilder()->build($request, $routeName, $replace, $removeKeys);
    }

    private function statisticsHeadingScope(
        StatisticsFilter $filter,
        ?string $hospitalDisplayName,
        string $locale,
        bool $loggedInUserHasNoAccessibleHospitals,
        int $accessibleHospitalCount,
    ): string {
        if (StatisticsFilterScope::MyHospitals === $filter->scope && $loggedInUserHasNoAccessibleHospitals) {
            return $this->getTranslator()->trans('stats.filter.scope.public', [], null, $locale);
        }

        if (1 === $accessibleHospitalCount && StatisticsFilterScope::Hospital === $filter->scope) {
            return $this->getTranslator()->trans('stats.filter.scope.my_hospitals', [], null, $locale);
        }

        return match ($filter->scope) {
            StatisticsFilterScope::Public => $this->getTranslator()->trans('stats.filter.scope.public', [], null, $locale),
            StatisticsFilterScope::MyHospitals => $this->getTranslator()->trans('stats.filter.scope.my_hospitals', [], null, $locale),
            StatisticsFilterScope::Hospital => (null !== $hospitalDisplayName && '' !== $hospitalDisplayName)
                ? $this->getTranslator()->trans('stats.filter.hospital.named_line', ['name' => $hospitalDisplayName], null, $locale)
                : $this->getTranslator()->trans('stats.filter.hospital.choose', [], null, $locale),
        };
    }

    private function statisticsHeadingPeriod(StatisticsFilter $filter, string $locale): string
    {
        $now = new \DateTimeImmutable();

        return match ($filter->period) {
            StatisticsFilterPeriod::All => $this->getTranslator()->trans('stats.filter.period.all', [], null, $locale),
            StatisticsFilterPeriod::AllTime => $this->getTranslator()->trans('stats.filter.period.all_time', [], null, $locale),
            StatisticsFilterPeriod::Year => $this->getTranslator()->trans(
                'stats.dashboard.heading.year',
                ['year' => (string) ($filter->referenceYear ?? $now->format('Y'))],
                null,
                $locale,
            ),
            StatisticsFilterPeriod::Month => $this->statisticsHeadingMonthPeriod($filter, $locale),
        };
    }

    private function statisticsHeadingMonthPeriod(StatisticsFilter $filter, string $locale): string
    {
        $now = new \DateTimeImmutable();
        $year = $filter->referenceYear ?? (int) $now->format('Y');
        $month = $filter->referenceMonth ?? (int) $now->format('n');
        $month = max(1, min(12, $month));
        $midMonth = new \DateTimeImmutable(sprintf('%04d-%02d-15 12:00:00', $year, $month));

        $pattern = 'LLLL yyyy';
        $formatted = \IntlDateFormatter::formatObject($midMonth, $pattern, $locale);
        if (false !== $formatted && '' !== $formatted) {
            return $formatted;
        }

        return sprintf('%04d-%02d', $year, $month);
    }
}
