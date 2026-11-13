<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Domain;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\MatchValidationItem;
use App\Encounter\Domain\Entity\MatchValidationSample;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class MatchValidationItemTest extends TestCase
{
    public function testPairItemTakesPairVerdictsOnly(): void
    {
        $item = new MatchValidationItem($this->sample(), 1, ValidationStratum::Automatic, $this->createStub(Allocation::class), $this->createStub(Encounter::class), '0.90–0.99', MatchStatus::AutoAccepted, 0.9697, 9, 1, '1.0.0');
        $user = $this->createStub(User::class);
        $at = new \DateTimeImmutable('2026-11-12 10:00');

        $item->record(ValidationVerdict::Wrong, $user, $at);
        self::assertSame([ValidationVerdict::Wrong, $at], [$item->getVerdict(), $item->getDecidedAt()]);

        $this->expectException(\InvalidArgumentException::class);
        $item->record(ValidationVerdict::Missed, $user, $at);
    }

    public function testReplacementGoesWithWrongOnly(): void
    {
        $item = new MatchValidationItem($this->sample(), 1, ValidationStratum::Automatic, $this->createStub(Allocation::class), $this->createStub(Encounter::class), '0.90–0.99', MatchStatus::AutoAccepted, 0.9697, 9, 1, '1.0.0');
        $user = $this->createStub(User::class);
        $at = new \DateTimeImmutable('2026-11-12 10:00');
        $other = $this->createStub(Encounter::class);

        $item->record(ValidationVerdict::Wrong, $user, $at, $other);
        self::assertSame($other, $item->getReplacementEncounter());

        // A later verdict without replacement clears it.
        $item->record(ValidationVerdict::Correct, $user, $at);
        self::assertNull($item->getReplacementEncounter());

        $this->expectException(\InvalidArgumentException::class);
        $item->record(ValidationVerdict::Correct, $user, $at, $other);
    }

    public function testUnlinkedVisitHasNoAllocation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MatchValidationItem($this->sample(), 1, ValidationStratum::UnlinkedVisit, $this->createStub(Allocation::class), $this->createStub(Encounter::class));
    }

    private function sample(): MatchValidationSample
    {
        return new MatchValidationSample($this->createStub(Hospital::class), new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), 20, 1, '1.0.0', [0.5, 0.7, 0.9, 0.99], [], null, new \DateTimeImmutable('2026-11-12'));
    }
}
