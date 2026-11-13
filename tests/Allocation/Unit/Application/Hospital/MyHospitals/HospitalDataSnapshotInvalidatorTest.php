<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\Hospital\MyHospitals\CachedHospitalDataSnapshotReader;
use App\Allocation\Application\Hospital\MyHospitals\HospitalDataSnapshotInvalidator;
use App\Allocation\Application\Message\WarmHospitalDataSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class HospitalDataSnapshotInvalidatorTest extends TestCase
{
    public function testDropsTheEntriesAndQueuesOneWarmMessagePerHospital(): void
    {
        $inner = new RecordingSnapshotReader();
        $reader = new CachedHospitalDataSnapshotReader($inner, new ArrayAdapter());
        $reader->read([1, 2, 3]);
        $bus = $this->recordingBus();

        new HospitalDataSnapshotInvalidator($reader, $bus)->invalidate([3, 1, 3]);

        self::assertSame([3, 1], array_map(static fn (WarmHospitalDataSnapshot $m): int => $m->hospitalId, $bus->messages));
        $reader->read([1, 2, 3]);
        self::assertSame([[1, 2, 3], [1, 3]], $inner->calls, 'Only the invalidated hospitals are read again.');
    }

    public function testNothingToInvalidateQueuesNothing(): void
    {
        $bus = $this->recordingBus();

        new HospitalDataSnapshotInvalidator(new CachedHospitalDataSnapshotReader(new RecordingSnapshotReader(), new ArrayAdapter()), $bus)->invalidate([]);

        self::assertSame([], $bus->messages);
    }

    /**
     * @return MessageBusInterface&object{messages: list<WarmHospitalDataSnapshot>}
     */
    private function recordingBus(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<WarmHospitalDataSnapshot> */
            public array $messages = [];

            #[\Override]
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof WarmHospitalDataSnapshot);
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
    }
}
