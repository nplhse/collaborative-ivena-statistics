<?php

declare(strict_types=1);

namespace App\Shared\Domain\Entity;

use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[Audit\Audited]
#[ORM\Entity(repositoryClass: CookieConsentRepository::class)]
#[ORM\Table(name: 'cookie_consent')]
#[ORM\UniqueConstraint(name: 'uniq_cookie_consent_subject', fields: ['subjectId'])]
class CookieConsent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $subjectId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 16)]
    private string $consentVersion = 'v1';

    /** @var array{essential: bool, monitoring: bool} */
    #[ORM\Column]
    private array $preferences = [
        'essential' => true,
        'monitoring' => false,
    ];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column]
    /** @psalm-suppress UnusedProperty */
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $subjectId)
    {
        $this->subjectId = $subjectId;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getConsentVersion(): string
    {
        return $this->consentVersion;
    }

    /**
     * @return array{essential: bool, monitoring: bool}
     */
    public function getPreferences(): array
    {
        return $this->preferences;
    }

    public function setMonitoringConsent(bool $enabled): self
    {
        $this->preferences = [
            'essential' => true,
            'monitoring' => $enabled,
        ];
        $this->decidedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
