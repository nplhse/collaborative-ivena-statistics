<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StatisticsPageViewModelFactory
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private TranslatorInterface $translator,
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    public function create(Request $request, string $routeName, ?User $user, StatisticsFilter $filter): StatisticsPageViewModel
    {
        $now = new \DateTimeImmutable();
        $defaultYear = $filter->referenceYear ?? (int) $now->format('Y');
        $defaultMonth = $filter->referenceMonth ?? (int) $now->format('n');

        $scopeUrls = [
            'public' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                ['scope' => StatisticsFilterScope::Public->value],
                ['hospital'],
            ),
            'my_hospitals' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                ['scope' => StatisticsFilterScope::MyHospitals->value],
                ['hospital'],
            ),
        ];

        $accessibleHospitals = [];
        $hospitalUrls = [];
        if ($user instanceof User) {
            $accessibleHospitals = $this->hospitalRepository->findAccessibleHospitalSummaries($user);
            foreach ($accessibleHospitals as $row) {
                $hospitalUrls[(string) $row['id']] = $this->statisticsNavigationUrlBuilder->build(
                    $request,
                    $routeName,
                    [
                        'scope' => StatisticsFilterScope::Hospital->value,
                        'hospital' => $row['id'],
                    ],
                );
            }
        }

        $periodUrls = [
            'all' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                ['period' => StatisticsFilterPeriod::All->value],
                ['year', 'month'],
            ),
            'all_time' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                ['period' => StatisticsFilterPeriod::AllTime->value],
                ['year', 'month'],
            ),
            'year' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [
                    'period' => StatisticsFilterPeriod::Year->value,
                    'year' => $defaultYear,
                ],
                ['month'],
            ),
            'month' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                [
                    'period' => StatisticsFilterPeriod::Month->value,
                    'year' => $defaultYear,
                    'month' => $defaultMonth,
                ],
            ),
        ];

        $hospitalDropdownSelectedName = null;
        if (StatisticsFilterScope::Hospital === $filter->scope && null !== $filter->hospitalId) {
            foreach ($accessibleHospitals as $row) {
                if ($row['id'] === $filter->hospitalId) {
                    $hospitalDropdownSelectedName = $row['name'];
                    break;
                }
            }
        }

        $showUnscopedHint = $user instanceof User
            && [] === $accessibleHospitals
            && StatisticsFilterScope::MyHospitals === $filter->scope;

        $locale = $request->getLocale();

        return new StatisticsPageViewModel(
            $filter,
            $scopeUrls,
            $hospitalUrls,
            $periodUrls,
            $accessibleHospitals,
            $hospitalDropdownSelectedName,
            $user instanceof User,
            $this->statisticsHeadingScope(
                $filter,
                $hospitalDropdownSelectedName,
                $locale,
                $showUnscopedHint,
                \count($accessibleHospitals),
            ),
            $this->statisticsHeadingPeriod($filter, $locale),
            $showUnscopedHint,
        );
    }

    private function statisticsHeadingScope(
        StatisticsFilter $filter,
        ?string $hospitalDisplayName,
        string $locale,
        bool $loggedInUserHasNoAccessibleHospitals,
        int $accessibleHospitalCount,
    ): string {
        if (StatisticsFilterScope::MyHospitals === $filter->scope && $loggedInUserHasNoAccessibleHospitals) {
            return $this->translator->trans('stats.filter.scope.public', [], null, $locale);
        }

        if (1 === $accessibleHospitalCount && StatisticsFilterScope::Hospital === $filter->scope) {
            return $this->translator->trans('stats.filter.scope.my_hospitals', [], null, $locale);
        }

        return match ($filter->scope) {
            StatisticsFilterScope::Public => $this->translator->trans('stats.filter.scope.public', [], null, $locale),
            StatisticsFilterScope::MyHospitals => $this->translator->trans('stats.filter.scope.my_hospitals', [], null, $locale),
            StatisticsFilterScope::Hospital => (null !== $hospitalDisplayName && '' !== $hospitalDisplayName)
                ? $this->translator->trans('stats.filter.hospital.named_line', ['name' => $hospitalDisplayName], null, $locale)
                : $this->translator->trans('stats.filter.hospital.choose', [], null, $locale),
        };
    }

    private function statisticsHeadingPeriod(StatisticsFilter $filter, string $locale): string
    {
        $now = new \DateTimeImmutable();

        return match ($filter->period) {
            StatisticsFilterPeriod::All => $this->translator->trans('stats.filter.period.all', [], null, $locale),
            StatisticsFilterPeriod::AllTime => $this->translator->trans('stats.filter.period.all_time', [], null, $locale),
            StatisticsFilterPeriod::Year => $this->translator->trans(
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
