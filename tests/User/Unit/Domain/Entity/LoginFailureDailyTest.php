<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Domain\Entity;

use App\User\Domain\Entity\LoginFailureDaily;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoginFailureDaily::class)]
final class LoginFailureDailyTest extends TestCase
{
    public function testADayIsFlaggedFromTenIpsForOneUsername(): void
    {
        self::assertFalse($this->day(maxIpsPerIdentifier: 9, maxIdentifiersPerIp: 1)->isFlagged());

        $day = $this->day(maxIpsPerIdentifier: 10, maxIdentifiersPerIp: 1);
        self::assertTrue($day->hasIdentifierFromManyIps());
        self::assertFalse($day->hasIpAgainstManyIdentifiers());
        self::assertTrue($day->isFlagged());
    }

    public function testADayIsFlaggedFromTenUsernamesForOneIp(): void
    {
        self::assertFalse($this->day(maxIpsPerIdentifier: 1, maxIdentifiersPerIp: 9)->isFlagged());

        $day = $this->day(maxIpsPerIdentifier: 1, maxIdentifiersPerIp: 10);
        self::assertFalse($day->hasIdentifierFromManyIps());
        self::assertTrue($day->hasIpAgainstManyIdentifiers());
        self::assertTrue($day->isFlagged());
    }

    private function day(int $maxIpsPerIdentifier, int $maxIdentifiersPerIp): LoginFailureDaily
    {
        return new LoginFailureDaily(new \DateTimeImmutable('2026-11-27'), 20, 0, 0, 0, 10, 10, $maxIpsPerIdentifier, $maxIdentifiersPerIp);
    }
}
