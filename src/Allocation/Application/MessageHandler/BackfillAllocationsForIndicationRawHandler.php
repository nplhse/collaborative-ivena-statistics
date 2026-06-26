<?php

declare(strict_types=1);

namespace App\Allocation\Application\MessageHandler;

use App\Allocation\Application\Indication\BackfillAllocationIndicationNormalizedService;
use App\Allocation\Application\Message\BackfillAllocationsForIndicationRawMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class BackfillAllocationsForIndicationRawHandler
{
    public function __construct(
        private BackfillAllocationIndicationNormalizedService $backfillService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BackfillAllocationsForIndicationRawMessage $message): void
    {
        $result = $this->backfillService->runForIndicationRawId($message->indicationRawId);

        $this->logger->info('indication_raw.backfill.completed', [
            'indication_raw_id' => $message->indicationRawId,
            'allocations_primary' => $result->allocationsPrimaryUpdated,
            'allocations_secondary' => $result->allocationsSecondaryUpdated,
            'projection_rows' => $result->projectionRowsUpdated,
        ]);
    }
}
