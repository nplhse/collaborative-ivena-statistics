<?php

declare(strict_types=1);

namespace App\User\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Shared\Application\Locale\SupportedLocales;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Security\UserRole;
use App\User\Domain\Validator\UserUsernameConstraints;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** @psalm-suppress ClassMustBeFinal */
#[Audit\Audited]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface, BackupCodeInterface, \Stringable
{
    public const int DISPLAY_NAME_MAX_LENGTH = 100;

    /** Alphabet of the stand-in secret used when the stored one cannot be decrypted (`getTotpAuthenticationConfiguration()`). */
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(min: UserUsernameConstraints::MIN_LENGTH, max: UserUsernameConstraints::MAX_LENGTH)]
    #[Assert\Regex(
        pattern: UserUsernameConstraints::PATTERN,
        message: 'validation.username.format',
    )]
    #[Assert\Regex(
        pattern: UserUsernameConstraints::DIGITS_ONLY_PATTERN,
        message: 'validation.username.digits_only',
        match: false,
    )]
    private ?string $username = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    /** Optional name shown to signed-in users instead of the username (#462). */
    #[ORM\Column(length: self::DISPLAY_NAME_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::DISPLAY_NAME_MAX_LENGTH)]
    private ?string $displayName = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    #[Audit\AuditSensitive]
    private ?string $password = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $credentialsExpired = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isEnabled = true;

    /** Opt-in for signing in with a one-time link sent by email (#1355); off by default. */
    #[ORM\Column(options: ['default' => false])]
    private bool $loginLinkEnabled = false;

    /**
     * Two-factor authentication with an authenticator app (#1357, ADR 040): the TOTP secret, encrypted with
     * `APP_TWO_FACTOR_KEY` (`TwoFactorSecretCipher`). Set together with `$totpEnabledAt` once the first code is confirmed.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Audit\AuditSensitive]
    private ?string $totpSecretEncrypted = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    /** Password and login link sign-ins are refused until then after too many failed passwords (#1762, `AccountLock`). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $loginLockedUntil = null;

    /**
     * Password hashes of the unused backup codes; a used code is removed.
     *
     * @var list<string>
     */
    #[ORM\Column(options: ['default' => '[]'])]
    #[Audit\AuditSensitive]
    private array $backupCodeHashes = [];

    /** Decrypted TOTP secret, filled after loading (`TwoFactorSecretLoader`); never persisted or serialized. */
    private ?string $totpSecret = null;

    /**
     * Replaced by the preference override of `engagement.monthly_reminder` (ADR 016, step 7b). Kept
     * mapped until the column is dropped in a later release; nothing reads it any more.
     *
     * @psalm-suppress UnusedProperty
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $receivesMonthlySubmissionReminder = true;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $locale = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Hospital>
     */
    #[ORM\OneToMany(targetEntity: Hospital::class, mappedBy: 'owner')]
    private Collection $hospitals;

    public function __construct()
    {
        $this->hospitals = new ArrayCollection();
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = UserUsernameConstraints::trim($username);

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = mb_strtolower(trim($email));

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    /**
     * Trims the name and collapses inner whitespace; an empty name is stored as null.
     */
    public function setDisplayName(?string $displayName): static
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $displayName ?? ''));
        $this->displayName = '' === $normalized ? null : $normalized;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     *
     * @psalm-suppress MoreSpecificReturnType
     */
    #[\Override]
    public function getUserIdentifier(): string
    {
        if (null === $this->username || '' === $this->username) {
            throw new \LogicException('User has no username set');
        }

        return $this->username;
    }

    /**
     * @see UserInterface
     */
    #[\Override]
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    #[\Override]
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    public function isCredentialsExpired(): bool
    {
        return $this->credentialsExpired;
    }

    public function setCredentialsExpired(bool $credentialsExpired): static
    {
        $this->credentialsExpired = $credentialsExpired;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function isLoginLinkEnabled(): bool
    {
        return $this->loginLinkEnabled;
    }

    public function setLoginLinkEnabled(bool $loginLinkEnabled): static
    {
        $this->loginLinkEnabled = $loginLinkEnabled;

        return $this;
    }

    /**
     * Whether a login link may be sent to and used by this user (#1355): only after opting in, never for
     * administrators. Part of the login link signature (`security.yaml`), so opting out or becoming an administrator
     * invalidates links that are still open.
     */
    public function isLoginLinkAllowed(): bool
    {
        return $this->loginLinkEnabled && !\in_array(UserRole::ADMIN, $this->getRoles(), true);
    }

    /**
     * Two-factor authentication is on once the first code was confirmed (#1357). It stays on when the secret cannot be
     * decrypted (missing or changed `APP_TWO_FACTOR_KEY`): codes are then rejected, backup codes still work.
     */
    #[\Override]
    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabledAt instanceof \DateTimeImmutable && null !== $this->totpSecretEncrypted;
    }

    #[\Override]
    public function getTotpAuthenticationUsername(): string
    {
        return $this->getUserIdentifier();
    }

    /**
     * Without a decrypted secret a random one stands in, so no code matches: the check fails closed instead of skipping
     * the second factor.
     */
    #[\Override]
    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (!$this->isTotpAuthenticationEnabled()) {
            return null;
        }

        return new TotpConfiguration($this->totpSecret ?? $this->unusableSecret(), TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function getTotpSecretEncrypted(): ?string
    {
        return $this->totpSecretEncrypted;
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    /** Called after loading with the decrypted secret, or null when it cannot be decrypted. */
    public function revealTotpSecret(?string $totpSecret): void
    {
        $this->totpSecret = $totpSecret;
    }

    /**
     * @param list<string> $backupCodeHashes
     */
    public function enableTotpAuthentication(string $encryptedSecret, string $secret, array $backupCodeHashes, \DateTimeImmutable $enabledAt): void
    {
        $this->totpSecretEncrypted = $encryptedSecret;
        $this->totpSecret = $secret;
        $this->backupCodeHashes = $backupCodeHashes;
        $this->totpEnabledAt = $enabledAt;
    }

    public function disableTotpAuthentication(): void
    {
        $this->totpSecretEncrypted = null;
        $this->totpSecret = null;
        $this->backupCodeHashes = [];
        $this->totpEnabledAt = null;
    }

    public function getLoginLockedUntil(): ?\DateTimeImmutable
    {
        return $this->loginLockedUntil;
    }

    public function isLoginLockedAt(\DateTimeImmutable $now): bool
    {
        return $this->loginLockedUntil instanceof \DateTimeImmutable && $this->loginLockedUntil > $now;
    }

    public function lockLoginUntil(\DateTimeImmutable $until): void
    {
        $this->loginLockedUntil = $until;
    }

    public function clearLoginLock(): void
    {
        $this->loginLockedUntil = null;
    }

    /**
     * @param list<string> $backupCodeHashes
     */
    public function replaceBackupCodes(array $backupCodeHashes): void
    {
        $this->backupCodeHashes = $backupCodeHashes;
    }

    public function countBackupCodes(): int
    {
        return \count($this->backupCodeHashes);
    }

    /**
     * Backup codes contain letters only, so six-digit TOTP codes are rejected without hashing.
     */
    #[\Override]
    public function isBackupCode(string $code): bool
    {
        return null !== $this->findBackupCodeHash($code);
    }

    #[\Override]
    public function invalidateBackupCode(string $code): void
    {
        $hash = $this->findBackupCodeHash($code);
        if (null !== $hash) {
            $this->backupCodeHashes = array_values(array_filter(
                $this->backupCodeHashes,
                static fn (string $stored): bool => $stored !== $hash,
            ));
        }
    }

    /** Upper case, letters only: "abcde-fghij" and "ABCDEFGHIJ" are the same code. */
    public static function normalizeBackupCode(string $code): string
    {
        return (string) preg_replace('/[^A-Z]/', '', mb_strtoupper($code));
    }

    private function findBackupCodeHash(string $code): ?string
    {
        $normalized = self::normalizeBackupCode($code);
        if ('' === $normalized || 1 === preg_match('/\d/', $code)) {
            return null;
        }

        foreach ($this->backupCodeHashes as $hash) {
            if (password_verify($normalized, $hash)) {
                return $hash;
            }
        }

        return null;
    }

    private function unusableSecret(): string
    {
        $secret = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $secret .= self::BASE32_ALPHABET[\ord($byte) & 31];
        }

        return $secret;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function hasExplicitLocale(): bool
    {
        return SupportedLocales::isSupported($this->locale);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /** @psalm-suppress PossiblyUnusedMethod Doctrine lifecycle callback */
    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->setUpdatedAt(Clock::get()->now());
    }

    /**
     * @return Collection<int, Hospital>
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getHospitals(): Collection
    {
        return $this->hospitals;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function addHospital(Hospital $hospital): static
    {
        if (!$this->hospitals->contains($hospital)) {
            $this->hospitals->add($hospital);
            $hospital->setOwner($this);
        }

        return $this;
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public function removeHospital(Hospital $hospital): static
    {
        // set the owning side to null (unless already changed)
        if ($this->hospitals->removeElement($hospital) && $hospital->getOwner() === $this) {
            $hospital->setOwner(null);
        }

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        /* @psalm-suppress PossiblyNullArgument */
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password ?? '');
        // The decrypted TOTP secret never goes into the session; it is decrypted again when the user is refreshed.
        unset($data["\0".self::class."\0totpSecret"]);

        return $data;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->getUserIdentifier();
    }
}
