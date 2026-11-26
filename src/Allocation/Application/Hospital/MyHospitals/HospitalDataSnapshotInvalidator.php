<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\MyHospitals;

use App\Allocation\Application\Contracts\HospitalDataSnapshotInvalidatorInterface;
use App\Allocation\Application\Message\WarmHospitalDataSnapshot;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Drops the cached snapshots at once, so the next page view reads fresh data, and queues one warm message per
 * hospital. On the Doctrine transport the message is stored in the caller's transaction and only runs once the
 * change is committed; the warm handler overwrites whatever a page view cached in between.
 *
 * @psalm-suppress UnusedClass wired through HospitalDataSnapshotInvalidatorInterface
 */
final readonly class HospitalDataSnapshotInvalidator implements HospitalDataSnapshotInvalidatorInterface
{
    public function __construct(
        private CachedHospitalDataSnapshotReader $reader,
        private MessageBusInterface $messageBus,
    ) {
    }

    #[\Override]
    public function invalidate(array $hospitalIds): void
    {
        $hospitalIds = array_values(array_unique($hospitalIds));
        if ([] === $hospitalIds) {
            return;
        }

        $this->reader->forget($hospitalIds);
        foreach ($hospitalIds as $hospitalId) {
            // Inside a message handler (an import run, a projection rebuild) the message waits until that handler
            // succeeded, so it neither runs nested in it nor warms data that is rolled back.
            $this->messageBus->dispatch(new WarmHospitalDataSnapshot($hospitalId), [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
