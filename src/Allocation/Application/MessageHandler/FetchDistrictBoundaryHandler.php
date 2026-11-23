<?php

declare(strict_types=1);

namespace App\Allocation\Application\MessageHandler;

use App\Allocation\Application\Geo\DistrictBoundaryFetch;
use App\Allocation\Application\Message\FetchDistrictBoundaryMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FetchDistrictBoundaryHandler
{
    public function __construct(
        private DistrictBoundaryFetch $fetch,
    ) {
    }

    public function __invoke(FetchDistrictBoundaryMessage $message): void
    {
        $this->fetch->run($message->districtId, $message->attemptId);
    }
}
