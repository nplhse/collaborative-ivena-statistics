<?php

declare(strict_types=1);

namespace App\Shared\Domain\Entity;

use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\Shared\Infrastructure\Repository\CookieConsentRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

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

    /**
     * Version of the consent text a decision was made under. v1: analytics only inside the application.
     * v2: with analytics consent, error and performance reports to the operator's monitoring service may also carry
     * the internal user id. A decision is always recorded under the current version.
     */
    public const string CURRENT_VERSION = 'v2';

    #[ORM\Column(length: 16)]
    private string $consentVersion = self::CURRENT_VERSION;

    /** @var array{essential: bool, analytics: bool} */
    #[ORM\Column]
    private array $preferences = [
        'essential' => true,
        'analytics' => false,
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
        $now = Clock::get()->now();
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
     * @return array{essential: bool, analytics: bool}
     */
    public function getPreferences(): array
    {
        return [
            'essential' => true,
            'analytics' => $this->preferences['analytics'] ?? false,
        ];
    }

    public function setConsents(bool $analytics): self
    {
        $this->preferences = [
            'essential' => true,
            'analytics' => $analytics,
        ];
        $this->consentVersion = self::CURRENT_VERSION;
        $this->decidedAt = Clock::get()->now();
        $this->updatedAt = Clock::get()->now();

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
