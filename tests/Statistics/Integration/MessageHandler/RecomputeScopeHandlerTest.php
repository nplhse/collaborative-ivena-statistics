<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\MessageHandler;

use App\Statistics\Application\Contract\CalculatorInterface;
use App\Statistics\Application\Message\RecomputeScope;
use App\Statistics\Application\MessageHandler\RecomputeScopeHandler;
use App\Statistics\Domain\Model\Scope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class RecomputeScopeHandlerTest extends TestCase
{
    private function makeMessage(
        string $type = 'hospital',
        string $id = '123',
        string $gran = 'day',
        string $key = '2025-11-01',
    ): RecomputeScope {
        $msg = new RecomputeScope(
            scopeType: $type,
            scopeId: $id,
            granularity: $gran,
            periodKey: $key,
        );

        return $msg;
    }

    public function testInvokesOnlySupportingCalculatorsAndReleasesLock(): void
    {
        $message = $this->makeMessage('state', '17', 'week', '2025-10-27');

        // Lock that acquires successfully and is released once.
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())
            ->method('createLock')
            ->with(self::callback(fn ($key) => is_string($key) && '' !== $key)) // don’t assert exact value of lockKey()
            ->willReturn($lock);

        // Calculator A supports and should be called once with the composed Scope
        $calcA = $this->createMock(CalculatorInterface::class);
        $calcA->expects($this->once())
            ->method('supports')
            ->with(self::callback(function (Scope $s) use ($message) {
                return $s->scopeType === $message->scopeType
                    && $s->scopeId === $message->scopeId
                    && $s->granularity === $message->granularity
                    && $s->periodKey === $message->periodKey;
            }))
            ->willReturn(true);
        $calcA->expects($this->once())->method('calculate')->with(self::isInstanceOf(Scope::class));

        // Calculator B does not support and must not be called for calculate()
        $calcB = $this->createMock(CalculatorInterface::class);
        $calcB->expects($this->once())->method('supports')->with(self::isInstanceOf(Scope::class))->willReturn(false);
        $calcB->expects($this->never())->method('calculate');

        $handler = new RecomputeScopeHandler([$calcA, $calcB], $lockFactory);
        $handler($message);
    }

    public function testReturnsEarlyWhenLockNotAcquired(): void
    {
        $message = $this->makeMessage();

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->willReturn(false);
        $lock->expects($this->never())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())->method('createLock')->willReturn($lock);

        $calc = $this->createMock(CalculatorInterface::class);
        $calc->expects($this->never())->method('supports');
        $calc->expects($this->never())->method('calculate');

        $handler = new RecomputeScopeHandler([$calc], $lockFactory);
        $handler($message);
    }

    public function testReleasesLockEvenIfCalculatorThrows(): void
    {
        $message = $this->makeMessage();

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())->method('createLock')->willReturn($lock);

        $calcThatThrows = $this->createMock(CalculatorInterface::class);
        $calcThatThrows->method('supports')->willReturn(true);
        $calcThatThrows->expects($this->once())
            ->method('calculate')
            ->willThrowException(new \RuntimeException('Boom'));

        $calcNeverReached = $this->createMock(CalculatorInterface::class);
        $calcNeverReached->expects($this->never())->method('supports');
        $calcNeverReached->expects($this->never())->method('calculate');

        $handler = new RecomputeScopeHandler([$calcThatThrows, $calcNeverReached], $lockFactory);

        try {
            $handler($message);
            self::fail('Expected exception from calculator to bubble up.');
        } catch (\RuntimeException $e) {
            self::assertSame('Boom', $e->getMessage());
        }
    }
}
