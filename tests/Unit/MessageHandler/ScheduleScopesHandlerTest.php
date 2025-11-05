<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Contract\ScopeProviderInterface;
use App\Message\ScheduleScope;
use App\MessageHandler\ScheduleScopesHandler;
use App\Model\Scope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class ScheduleScopesHandlerTest extends TestCase
{
    public function testDispatchesMessagesForAllProvidersWithCorrectStamps(): void
    {
        $importId = 777;

        // Provider #1 yields: hospital + public
        $prov1 = $this->createMock(ScopeProviderInterface::class);
        $prov1->expects($this->once())
            ->method('provideForImport')
            ->with($importId)
            ->willReturn([
                new Scope('hospital', 'h1', 'day', '2025-11-01'),
                new Scope('public', 'all', 'day', '2025-11-01'),
            ]);

        // Provider #2 yields: state
        $prov2 = $this->createMock(ScopeProviderInterface::class);
        $prov2->expects($this->once())
            ->method('provideForImport')
            ->with($importId)
            ->willReturn([
                new Scope('state', '17', 'year', '2025-01-01'),
            ]);

        $bus = $this->createMock(MessageBusInterface::class);

        $dispatched = []; // capture calls (message, stamps)
        $bus->expects($this->exactly(3))
            ->method('dispatch')
            ->willReturnCallback(function (object $msg, array $stamps = []) use (&$dispatched) {
                $dispatched[] = [$msg, $stamps];

                return new Envelope($msg, $stamps); // <- return a real Envelope
            });

        $handler = new ScheduleScopesHandler([$prov1, $prov2], $bus);
        $handler(new ScheduleScope(importId: $importId));
    }

    public function testDoesNotDispatchWhenProvidersYieldNothing(): void
    {
        $importId = 123;

        $prov1 = $this->createMock(ScopeProviderInterface::class);
        $prov1->expects($this->once())
            ->method('provideForImport')
            ->with($importId)
            ->willReturn([]);

        $prov2 = $this->createMock(ScopeProviderInterface::class);
        $prov2->expects($this->once())
            ->method('provideForImport')
            ->with($importId)
            ->willReturn(new \ArrayIterator([])); // iterable but empty

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $handler = new ScheduleScopesHandler([$prov1, $prov2], $bus);
        $handler(new ScheduleScope(importId: $importId));
    }

    public function testHospitalScopesGetHighPriorityTransportOnly(): void
    {
        $importId = 9;

        $prov = $this->createMock(ScopeProviderInterface::class);
        $prov->expects($this->once())
            ->method('provideForImport')
            ->with($importId)
            ->willReturn([
                new Scope('hospital', 'H-42', 'week', '2025-10-27'),      // should get stamp
                new Scope('dispatch_area', 'DA-1', 'day', '2025-11-01'),  // no stamp
            ]);

        $bus = $this->createMock(MessageBusInterface::class);

        // Capture calls (message, stamps) and always return a real Envelope.
        $calls = [];
        $bus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $message, array $stamps = []) use (&$calls) {
                $calls[] = [$message, $stamps];

                return new Envelope($message, $stamps);
            });

        $handler = new ScheduleScopesHandler([$prov], $bus);
        $handler(new ScheduleScope(importId: $importId));

        // ---- assertions on captured calls ----
        self::assertCount(2, $calls);

        // 1) hospital → one TransportNamesStamp(['async_priority_high'])
        [$firstMsg, $firstStamps] = $calls[0];
        $ref = new \ReflectionClass($firstMsg);
        $p = $ref->getProperty('scopeType');
        $p->setAccessible(true);
        self::assertSame('hospital', $p->getValue($firstMsg));
        self::assertCount(1, $firstStamps);
        self::assertInstanceOf(
            TransportNamesStamp::class,
            $firstStamps[0]
        );
        self::assertSame(['async_priority_high'], $firstStamps[0]->getTransportNames());

        // 2) dispatch_area → no stamps
        [$secondMsg, $secondStamps] = $calls[1];
        $p = (new \ReflectionClass($secondMsg))->getProperty('scopeType');
        $p->setAccessible(true);
        self::assertSame('dispatch_area', $p->getValue($secondMsg));
        self::assertSame([], $secondStamps);
    }
}
