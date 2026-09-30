<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureAnalyticsFilter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ClosureAllocationExploreUrlFactory
{
    public const string DATE_QUERY_FORMAT = 'Y-m-d';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function listUrl(
        StatisticsFilter $filter,
        ClosureAnalyticsFilter $closureFilter,
        int $hospitalId,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): string {
        $timezone = new \DateTimeZone(ClosureDayTimelineFactory::TIMEZONE);
        $from = $startsAt->setTimezone($timezone);
        $until = $this->inclusiveEnd($endsAt->setTimezone($timezone));

        return $this->urlGenerator->generate('app_explore_allocation_list', [
            'hospitalFilter' => $this->hospitalFilter($filter, $closureFilter, $hospitalId),
            'createdFrom' => $from->format(self::DATE_QUERY_FORMAT),
            'createdUntil' => $until->format(self::DATE_QUERY_FORMAT),
        ]);
    }

    private function hospitalFilter(
        StatisticsFilter $filter,
        ClosureAnalyticsFilter $closureFilter,
        int $hospitalId,
    ): string {
        if (StatisticsFilterScope::Hospital === $filter->scope && null !== $filter->hospitalId) {
            return (string) $filter->hospitalId;
        }

        if (1 === \count($closureFilter->hospitalIds)) {
            return (string) $closureFilter->hospitalIds[0];
        }

        if ([] !== $closureFilter->hospitalIds) {
            return (string) $hospitalId;
        }

        if (StatisticsFilterScope::MyHospitals === $filter->scope) {
            return 'my_hospitals';
        }

        return (string) $hospitalId;
    }

    private function inclusiveEnd(\DateTimeImmutable $endsAt): \DateTimeImmutable
    {
        if ('00:00:00' === $endsAt->format('H:i:s')) {
            return $endsAt->modify('-1 second');
        }

        return $endsAt;
    }
}
