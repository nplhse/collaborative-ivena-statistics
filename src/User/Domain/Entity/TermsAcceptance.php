<?php

declare(strict_types=1);

namespace App\User\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\User\Domain\Enum\TermsAcceptanceKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * One acceptance of the terms of use by one account (#1564, ADR 043): which version, when, in which language, and the
 * SHA-256 of the terms page content that was published then. An upload authority (#1565) also names the hospital.
 * Written once, never changed; deleted with the account or the hospital.
 *
 * @psalm-suppress ClassMustBeFinal
 */
#[ORM\Entity]
#[ORM\Table(name: 'terms_acceptance')]
#[ORM\Index(name: 'idx_terms_acceptance_user_kind', columns: ['user_id', 'kind', 'accepted_at'])]
#[ORM\Index(name: 'idx_terms_acceptance_user_hospital', columns: ['user_id', 'hospital_id', 'kind'])]
class TermsAcceptance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: TermsAcceptanceKind::class)]
    private TermsAcceptanceKind $kind;

    /** Null only for a registration while no version is configured. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $termsVersion;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $acceptedAt;

    #[ORM\Column(length: 8)]
    private string $locale;

    /** Null when no published terms page existed in this language. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $contentHash;

    /** The hospital of an upload authority (#1565); null for the terms of use. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Hospital $hospital;

    public function __construct(
        User $user,
        TermsAcceptanceKind $kind,
        ?string $termsVersion,
        string $locale,
        ?string $contentHash,
        ?\DateTimeImmutable $acceptedAt = null,
        ?Hospital $hospital = null,
    ) {
        if ($kind->requiresHospital() !== $hospital instanceof Hospital) {
            throw new \InvalidArgumentException(\sprintf('An acceptance of kind "%s" %s a hospital.', $kind->value, $kind->requiresHospital() ? 'needs' : 'takes no'));
        }

        $this->user = $user;
        $this->hospital = $hospital;
        $this->kind = $kind;
        $this->termsVersion = $termsVersion;
        $this->locale = $locale;
        $this->contentHash = $contentHash;
        $this->acceptedAt = $acceptedAt ?? Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKind(): TermsAcceptanceKind
    {
        return $this->kind;
    }

    public function getTermsVersion(): ?string
    {
        return $this->termsVersion;
    }

    public function getAcceptedAt(): \DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function getHospital(): ?Hospital
    {
        return $this->hospital;
    }
}
