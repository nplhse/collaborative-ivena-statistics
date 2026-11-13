<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\VitalsReference;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Initial vitals of one ED visit in the units of the exchange format (mmHg, /min, °C, mg/dl, %, GCS).
 *
 * The reference says whether the values were measured with a documented reference to the admission
 * (`admission_measurement`, then with `measuredAt`) or are documented first values without an assured
 * time reference (`documented_initial`). It is required as soon as any value is set (ADR 026).
 * Plausibility ranges are checked by the import (#1048), not here.
 */
#[ORM\Embeddable]
class InitialVitals
{
    /**
     * @param numeric-string|null $temperature °C with one decimal, as stored in a DECIMAL column
     */
    public function __construct(
        #[ORM\Column(name: 'reference', type: Types::STRING, length: 32, nullable: true, enumType: VitalsReference::class)]
        private ?VitalsReference $reference = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $measuredAt = null,
        #[ORM\Column(type: Types::SMALLINT, nullable: true)]
        private ?int $bpSystolic = null,
        #[ORM\Column(type: Types::SMALLINT, nullable: true)]
        private ?int $bpDiastolic = null,
        #[ORM\Column(type: Types::SMALLINT, nullable: true)]
        private ?int $heartRate = null,
        #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
        private ?string $temperature = null,
        #[ORM\Column(type: Types::SMALLINT, nullable: true)]
        private ?int $respiratoryRate = null,
        #[ORM\Column(type: Types::SMALLINT, nullable: true)]
        private ?int $glucose = null,
        #[ORM\Column(type: Types::SMALLINT, nullable: true)]
        private ?int $spo2 = null,
        #[ORM\Column(type: Types::SMALLINT, nullable: true)]
        private ?int $gcs = null,
    ) {
        if (!$reference instanceof VitalsReference && $this->hasAnyValue()) {
            throw new \InvalidArgumentException('Initial vitals need a vitals reference when any value is set.');
        }

        if ($measuredAt instanceof \DateTimeImmutable && VitalsReference::AdmissionMeasurement !== $reference) {
            throw new \InvalidArgumentException('A measurement time is only allowed with the reference "admission_measurement".');
        }
    }

    public function hasAnyValue(): bool
    {
        return array_any([$this->bpSystolic, $this->bpDiastolic, $this->heartRate, $this->temperature, $this->respiratoryRate, $this->glucose, $this->spo2, $this->gcs], fn (string|int|null $value): bool => null !== $value);
    }

    public function getReference(): ?VitalsReference
    {
        return $this->reference;
    }

    public function getMeasuredAt(): ?\DateTimeImmutable
    {
        return $this->measuredAt;
    }

    public function getBpSystolic(): ?int
    {
        return $this->bpSystolic;
    }

    public function getBpDiastolic(): ?int
    {
        return $this->bpDiastolic;
    }

    public function getHeartRate(): ?int
    {
        return $this->heartRate;
    }

    /**
     * @return numeric-string|null
     */
    public function getTemperature(): ?string
    {
        return $this->temperature;
    }

    public function getRespiratoryRate(): ?int
    {
        return $this->respiratoryRate;
    }

    public function getGlucose(): ?int
    {
        return $this->glucose;
    }

    public function getSpo2(): ?int
    {
        return $this->spo2;
    }

    public function getGcs(): ?int
    {
        return $this->gcs;
    }
}
