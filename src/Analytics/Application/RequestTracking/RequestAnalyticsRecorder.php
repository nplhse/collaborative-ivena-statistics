<?php

declare(strict_types=1);

namespace App\Analytics\Application\RequestTracking;

use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;

final readonly class RequestAnalyticsRecorder
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AnalyticsRequestRepository $repository,
    ) {
    }

    /**
     * @param list<string> $queryParamNames
     */
    public function record(
        \DateTimeImmutable $occurredAt,
        ?string $routeName,
        FeatureArea $featureArea,
        int $httpStatus,
        int $durationMs,
        int $dbQueryCount,
        int $dbTimeMs,
        bool $isAuthenticated,
        ?string $userRole,
        ?string $analyticsUserKey,
        ?string $visitorKey,
        ?string $sessionKey,
        BrowserFamily $browserFamily,
        DeviceType $deviceType,
        array $queryParamNames,
    ): void {
        $this->repository->save(new AnalyticsRequest(
            $occurredAt,
            $routeName,
            $featureArea,
            $httpStatus,
            $durationMs,
            $dbQueryCount,
            $dbTimeMs,
            $isAuthenticated,
            $userRole,
            $analyticsUserKey,
            $visitorKey,
            $sessionKey,
            $browserFamily,
            $deviceType,
            $queryParamNames,
        ));
    }
}
