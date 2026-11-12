<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\Domain;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Entity\EncounterDisposition;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Entity\InitialVitals;
use App\Encounter\Domain\Entity\Triage;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\CareLevel;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\Sex;
use App\Encounter\Domain\Enum\VitalsReference;
use App\Import\Domain\Entity\Import;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncounterTest extends TestCase
{
    public function testAnchorIsFirstAssessmentContactWhenSet(): void
    {
        $times = new EncounterTimes(
            edAdmissionAt: new \DateTimeImmutable('2026-10-12 10:02'),
            firstAssessmentContactAt: new \DateTimeImmutable('2026-10-12 10:05'),
        );

        self::assertSame(AnchorSource::Assessment, $times->anchorSource());
        self::assertEquals(new \DateTimeImmutable('2026-10-12 10:05'), $times->anchorAt());
    }

    public function testAnchorFallsBackToEdAdmission(): void
    {
        $times = new EncounterTimes(edAdmissionAt: new \DateTimeImmutable('2026-10-12 10:02'));

        self::assertSame(AnchorSource::Admission, $times->anchorSource());
        self::assertEquals(new \DateTimeImmutable('2026-10-12 10:02'), $times->anchorAt());
    }

    public function testVisitWithoutTimeAnchorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('time anchor');

        $this->encounter(times: new EncounterTimes(treatmentEndAt: new \DateTimeImmutable('2026-10-12 12:00')));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidAges(): iterable
    {
        yield 'zero' => [0];
        yield 'hundred' => [100];
    }

    #[DataProvider('invalidAges')]
    public function testAgeOutsideIvenaRangeIsRejected(int $age): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->encounter(age: $age);
    }

    public function testFingerprintMustBeLowercaseHexHmac(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->encounter(fingerprint: strtoupper(hash('sha256', 'x')));
    }

    public function testKeyGenerationStartsAtOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->encounter(keyGeneration: 0);
    }

    public function testCareLevelOnlyForAdmissionOrExternalTransfer(): void
    {
        self::assertTrue(new EncounterDisposition(Disposition::TransferredExternal, CareLevel::Unknown)->isExternalTransfer());

        $this->expectException(\InvalidArgumentException::class);
        new EncounterDisposition(Disposition::Home, CareLevel::Ward);
    }

    public function testVitalsNeedReferenceWhenAnyValueIsSet(): void
    {
        self::assertFalse(new InitialVitals()->hasAnyValue());

        $this->expectException(\InvalidArgumentException::class);
        new InitialVitals(heartRate: 80);
    }

    public function testMeasurementTimeOnlyWithAdmissionMeasurement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new InitialVitals(VitalsReference::DocumentedInitial, new \DateTimeImmutable('2026-10-12 10:07'), heartRate: 80);
    }

    public function testPainScoreRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Triage(painScore: 11);
    }

    private function encounter(
        int $age = 40,
        ?string $fingerprint = null,
        int $keyGeneration = 1,
        ?EncounterTimes $times = null,
    ): Encounter {
        return new Encounter(
            hospital: new Hospital(),
            import: new Import(),
            keyGeneration: $keyGeneration,
            visitFingerprint: $fingerprint ?? hash('sha256', 'visit'),
            caseKey: hash('sha256', 'case'),
            age: $age,
            sex: Sex::Female,
            times: $times ?? new EncounterTimes(edAdmissionAt: new \DateTimeImmutable('2026-10-12 10:02')),
        );
    }
}
