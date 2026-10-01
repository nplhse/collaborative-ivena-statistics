<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use App\Allocation\Application\ReferenceCatalog\ReferenceNameKey;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'speciality_alias')]
#[ORM\UniqueConstraint(name: 'uniq_speciality_alias_normalized_name', columns: ['normalized_name'])]
class SpecialityAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Speciality::class, inversedBy: 'aliases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Speciality $speciality;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $normalizedName;

    #[ORM\Column(length: 64)]
    private string $classification;

    #[ORM\Column(length: 64)]
    private string $source;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct(
        Speciality $speciality,
        string $name,
        string $classification,
        string $source,
        ?string $note = null,
        ?string $validFrom = null,
        ?string $validTo = null,
    ) {
        $this->speciality = $speciality;
        $this->name = $name;
        $this->normalizedName = ReferenceNameKey::normalize($name);
        $this->classification = $classification;
        $this->source = $source;
        $this->note = $note;
        $this->validFrom = $this->parseDate($validFrom);
        $this->validTo = $this->parseDate($validTo);
        $speciality->addAlias($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSpeciality(): Speciality
    {
        return $this->speciality;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalizedName(): string
    {
        return $this->normalizedName;
    }

    public function getClassification(): string
    {
        return $this->classification;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function matches(string $classification, string $source, ?string $note, ?string $validFrom, ?string $validTo): bool
    {
        return $this->classification === $classification
            && $this->source === $source
            && $this->note === $note
            && $this->formatDate($this->validFrom) === $validFrom
            && $this->formatDate($this->validTo) === $validTo;
    }

    public function applyMetadata(string $classification, string $source, ?string $note, ?string $validFrom, ?string $validTo): void
    {
        $this->classification = $classification;
        $this->source = $source;
        $this->note = $note;
        $this->validFrom = $this->parseDate($validFrom);
        $this->validTo = $this->parseDate($validTo);
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(sprintf('Alias date "%s" must use Y-m-d.', $value));
        }

        return $date;
    }

    private function formatDate(?\DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d');
    }
}
