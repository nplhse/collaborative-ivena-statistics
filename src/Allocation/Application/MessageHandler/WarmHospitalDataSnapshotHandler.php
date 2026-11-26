<?php

declare(strict_types=1);

namespace App\Allocation\Application\MessageHandler;

use App\Allocation\Application\Hospital\MyHospitals\CachedHospitalDataSnapshotReader;
use App\Allocation\Application\Message\WarmHospitalDataSnapshot;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class WarmHospitalDataSnapshotHandler
{
    public function __construct(
        private CachedHospitalDataSnapshotReader $reader,
    ) {
    }

    public function __invoke(WarmHospitalDataSnapshot $message): void
    {
        $this->reader->refresh([$message->hospitalId]);
    }
}
