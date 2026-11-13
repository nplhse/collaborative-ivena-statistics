<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * A curated problem area of the plausibility table (ADR 033): PZC codes that belong together clinically and the MTS
 * diagrams plausible with them, with points. Each PZC belongs to at most one area; "other" codes belong to none.
 *
 * The areas are the editable seed. The matcher never reads them: PlausibilityCuration builds a draft snapshot from
 * them after every change, and only an activated snapshot is read.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_plausibility_area')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_area_code', fields: ['code'])]
#[ORM\HasLifecycleCallbacks]
class PlausibilityProblemArea implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $germanName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $englishName = null;

    /** @var Collection<int, PlausibilityAreaCode> */
    #[ORM\OneToMany(targetEntity: PlausibilityAreaCode::class, mappedBy: 'area', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['pzc' => 'ASC'])]
    private Collection $codes;

    /** @var Collection<int, PlausibilityAreaDiagram> */
    #[ORM\OneToMany(targetEntity: PlausibilityAreaDiagram::class, mappedBy: 'area', cascade: ['persist'], orphanRemoval: true)]
    private Collection $diagrams;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $code, string $germanName, ?string $englishName = null)
    {
        $this->code = $code;
        $this->germanName = trim($germanName);
        $this->setEnglishName($englishName);
        $this->codes = new ArrayCollection();
        $this->diagrams = new ArrayCollection();
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getGermanName(): string
    {
        return $this->germanName;
    }

    public function setGermanName(string $germanName): void
    {
        $this->germanName = trim($germanName);
    }

    public function getEnglishName(): ?string
    {
        return $this->englishName;
    }

    public function setEnglishName(?string $englishName): void
    {
        $englishName = null === $englishName ? null : trim($englishName);
        $this->englishName = '' === $englishName ? null : $englishName;
    }

    /**
     * @return list<int> PZC codes, ascending
     */
    public function pzcCodes(): array
    {
        $codes = array_map(static fn (PlausibilityAreaCode $code): int => $code->getPzc(), $this->codes->toArray());
        sort($codes);

        return $codes;
    }

    /**
     * Keeps the codes that stay, removes the others and adds the new ones (no delete and insert of the same code).
     *
     * @param list<int> $pzcCodes
     */
    public function replaceCodes(array $pzcCodes): void
    {
        foreach ($this->codes as $code) {
            if (!\in_array($code->getPzc(), $pzcCodes, true)) {
                $this->codes->removeElement($code);
            }
        }

        $existing = $this->pzcCodes();
        foreach (array_unique($pzcCodes) as $pzc) {
            if (!\in_array($pzc, $existing, true)) {
                $this->codes->add(new PlausibilityAreaCode($this, $pzc));
            }
        }
    }

    /**
     * @return list<PlausibilityAreaDiagram> ordered by diagram code
     */
    public function diagrams(): array
    {
        $diagrams = $this->diagrams->toArray();
        usort($diagrams, static fn (PlausibilityAreaDiagram $a, PlausibilityAreaDiagram $b): int => $a->getDiagram()->getCode() <=> $b->getDiagram()->getCode());

        return $diagrams;
    }

    /**
     * @return array<string, int> points by diagram code
     */
    public function diagramPoints(): array
    {
        $points = [];
        foreach ($this->diagrams() as $rule) {
            $points[$rule->getDiagram()->getCode()] = $rule->getPoints();
        }

        return $points;
    }

    /**
     * Sets the plausible diagrams with their points; diagrams not in the list lose their rule.
     *
     * @param list<array{0: MtsDiagram, 1: int}> $rules
     */
    public function replaceDiagrams(array $rules): void
    {
        $wanted = [];
        foreach ($rules as [$diagram, $points]) {
            $wanted[$diagram->getCode()] = [$diagram, $points];
        }

        foreach ($this->diagrams as $rule) {
            $code = $rule->getDiagram()->getCode();
            if (!isset($wanted[$code])) {
                $this->diagrams->removeElement($rule);

                continue;
            }

            $rule->setPoints($wanted[$code][1]);
            unset($wanted[$code]);
        }

        foreach ($wanted as [$diagram, $points]) {
            $this->diagrams->add(new PlausibilityAreaDiagram($this, $diagram, $points));
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = Clock::get()->now();
    }

    #[\Override]
    public function __toString(): string
    {
        return \sprintf('%s (%s)', $this->germanName, $this->code);
    }
}
