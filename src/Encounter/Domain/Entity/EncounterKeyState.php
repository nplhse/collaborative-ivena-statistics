<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * Pseudonym key state of one hospital (ADR 026): the current key generation, the check value
 * registered for it and the retired check values of earlier generations.
 *
 * Every encounter stores the generation it was imported with; duplicate detection works within one
 * generation only. This step (#1047) only models the state. Registering the first check value and
 * rejecting retired ones belong to the import (#1048); the key change that increments the generation
 * and sets `keyChangeInProgress` belongs to #1050. A hospital without a row is at generation 1 with
 * no registered check value.
 *
 * @psalm-suppress UnusedClass Doctrine mapping of #1047; read and written by #1048 and #1050.
 */
#[ORM\Entity]
#[ORM\Table(name: 'encounter_key_state')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_key_state_hospital', fields: ['hospital'])]
class EncounterKeyState
{
    public const int FIRST_GENERATION = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Hospital $hospital;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => self::FIRST_GENERATION])]
    private int $keyGeneration = self::FIRST_GENERATION;

    /** Check value of the current generation; null until the first upload of the generation registers it. */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $activeCheckValue = null;

    /** @var list<string> check values of earlier generations; uploads carrying one are always rejected */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $retiredCheckValues = [];

    /** While true, new encounter uploads are refused (key change, ADR 026). */
    #[ORM\Column(options: ['default' => false])]
    private bool $keyChangeInProgress = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Hospital $hospital)
    {
        $this->hospital = $hospital;
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): Hospital
    {
        return $this->hospital;
    }

    public function getKeyGeneration(): int
    {
        return $this->keyGeneration;
    }

    public function getActiveCheckValue(): ?string
    {
        return $this->activeCheckValue;
    }

    /**
     * @return list<string>
     */
    public function getRetiredCheckValues(): array
    {
        return $this->retiredCheckValues;
    }

    public function isRetired(string $checkValue): bool
    {
        return \in_array($checkValue, $this->retiredCheckValues, true);
    }

    public function isKeyChangeInProgress(): bool
    {
        return $this->keyChangeInProgress;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
