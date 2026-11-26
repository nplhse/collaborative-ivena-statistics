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
     * Version of the consent texts a decision was made under; a decision is always recorded under the current version.
     * v1: usage analytics inside the application. v2: adds the performance option (traces to Sentry with the internal
     * user id, only offered when Sentry is used) and the notice that error reports always carry the user id.
     */
    public const string CURRENT_VERSION = 'v2';

    #[ORM\Column(length: 16)]
    private string $consentVersion = self::CURRENT_VERSION;

    /**
     * `performance` is a sub-option of `analytics`: it is only ever stored together with it.
     *
     * @var array{essential: bool, analytics: bool, performance?: bool}
     */
    #[ORM\Column]
    private array $preferences = [
        'essential' => true,
        'analytics' => false,
        'performance' => false,
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
     * Missing keys of older rows count as not given.
     *
     * @return array{essential: bool, analytics: bool, performance: bool}
     */
    public function getPreferences(): array
    {
        $analytics = $this->preferences['analytics'] ?? false;

        return [
            'essential' => true,
            'analytics' => $analytics,
            'performance' => $analytics && ($this->preferences['performance'] ?? false),
        ];
    }

    public function setConsents(bool $analytics, bool $performance = false): self
    {
        $this->preferences = [
            'essential' => true,
            'analytics' => $analytics,
            'performance' => $analytics && $performance,
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
