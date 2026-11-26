<?php

declare(strict_types=1);

namespace App\Analytics\Application\Service;

use App\Analytics\Application\DTO\AnalyticsAccessBackfillResult;
use App\Analytics\Application\RequestTracking\CountedAccessClassifier;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Infrastructure\Persistence\AnalyticsAccessClassificationStore;

/**
 * Classifies raw requests recorded before the counted-access flag existed (#950).
 *
 * Only the stored fields are available (status, route, device type), so frame navigations on page
 * routes and non-GET requests with a 2xx response cannot be recognised. Rows that are already
 * classified are never changed, so running it again is safe.
 */
final readonly class AnalyticsAccessBackfillService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsAccessClassificationStore $store,
        private CountedAccessClassifier $classifier,
    ) {
    }

    public function classifyStoredRequests(bool $dryRun = false): AnalyticsAccessBackfillResult
    {
        $rowsClassified = 0;
        $rowsCounted = 0;

        foreach ($this->store->findUnclassifiedGroups() as $group) {
            $counted = $this->classifier->isCountedStoredRequest(
                $group['httpStatus'],
                $group['routeName'],
                DeviceType::from($group['deviceType']),
            );

            $rows = $dryRun
                ? $group['rowCount']
                : $this->store->classifyGroup($group['routeName'], $group['httpStatus'], $group['deviceType'], $counted);

            $rowsClassified += $rows;
            if ($counted) {
                $rowsCounted += $rows;
            }
        }

        return new AnalyticsAccessBackfillResult($rowsClassified, $rowsCounted);
    }
}
