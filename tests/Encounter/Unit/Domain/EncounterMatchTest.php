<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Domain;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Entity\Import;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Invariants of a matched pair (ADR 030, #1053): same hospital, score 0–1, decisions only through decide().
 */
final class EncounterMatchTest extends TestCase
{
    private Hospital $hospital;

    #[\Override]
    protected function setUp(): void
    {
        $this->hospital = new Hospital();
    }

    public function testAProposalBecomesADecisionWithAuthorAndTime(): void
    {
        $match = $this->match(MatchStatus::Proposed, score: 0.8);
        self::assertSame(MatchStatus::Proposed, $match->getStatus());
        self::assertFalse($match->getStatus()->isEffective());
        self::assertSame($this->hospital, $match->getHospital());

        $user = new User();
        $match->decide(MatchStatus::Confirmed, $user, new \DateTimeImmutable('2026-11-12 10:00'));

        self::assertSame(MatchStatus::Confirmed, $match->getStatus());
        self::assertTrue($match->getStatus()->isEffective());
        self::assertSame($user, $match->getDecidedBy());
        self::assertEquals(new \DateTimeImmutable('2026-11-12 10:00'), $match->getDecidedAt());
    }

    public function testStatusKinds(): void
    {
        self::assertSame([MatchStatus::AutoAccepted, MatchStatus::Confirmed], MatchStatus::effective());
        self::assertTrue(MatchStatus::Rejected->isManual());
        self::assertFalse(MatchStatus::Rejected->isEffective());
        self::assertFalse(MatchStatus::AutoAccepted->isManual());
    }

    public function testOnlyConfirmOrRejectIsADecision(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->match(MatchStatus::Proposed)->decide(MatchStatus::AutoAccepted, new User(), new \DateTimeImmutable());
    }

    public function testAPairDoesNotStartAsDecision(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->match(MatchStatus::Rejected);
    }

    public function testTheScoreLiesBetweenZeroAndOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->match(MatchStatus::Proposed, score: 1.2);
    }

    public function testVisitAndAllocationBelongToTheSameHospital(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EncounterMatch(new Allocation()->setHospital(new Hospital()), $this->encounter(), MatchStatus::Proposed, AnchorSource::Admission, 5, new \DateTimeImmutable());
    }

    public function testContributionsKeepTheFeatureOrder(): void
    {
        $match = $this->match(MatchStatus::AutoAccepted, contributions: ['work_accident' => 0, 'age' => 2, 'time' => 4, 'extra' => 1]);

        self::assertSame(['time' => 4, 'age' => 2, 'work_accident' => 0, 'extra' => 1], $match->getContributions());
    }

    /**
     * @param array<string, int> $contributions
     */
    private function match(MatchStatus $status, ?float $score = null, array $contributions = []): EncounterMatch
    {
        return new EncounterMatch(
            new Allocation()->setHospital($this->hospital),
            $this->encounter(),
            $status,
            AnchorSource::Admission,
            5,
            new \DateTimeImmutable('2026-11-12 09:00'),
            score: $score,
            contributions: $contributions,
        );
    }

    private function encounter(): Encounter
    {
        return new Encounter(
            hospital: $this->hospital,
            import: new Import(),
            keyGeneration: 1,
            visitFingerprint: hash('sha256', 'visit'),
            caseKey: hash('sha256', 'case'),
            age: 70,
            sex: Sex::Female,
            times: new EncounterTimes(edAdmissionAt: new \DateTimeImmutable('2026-10-12 10:05')),
        );
    }
}
