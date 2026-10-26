<?php

declare(strict_types=1);

namespace App\Admin\Application\DTO\Messenger;

use App\Admin\Application\Service\Messenger\MessengerMonitoringPeriod;

final readonly class MessengerMonitoringReportDto
{
    /**
     * @param list<MessengerTransportRowDto>   $transports
     * @param list<MessengerMessageTypeRowDto> $messageTypes
     * @param list<MessengerFailureRowDto>     $failures
     */
    public function __construct(
        public MessengerMonitoringPeriod $period,
        public MessengerCountsDto $totals,
        public int $pending,
        public int $waitingForRetry,
        public int $failedQueue,
        public ?\DateTimeImmutable $lastActivityAt,
        public array $transports,
        public array $messageTypes,
        public array $failures,
    ) {
    }
}
