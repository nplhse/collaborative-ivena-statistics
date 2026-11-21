<?php

declare(strict_types=1);

namespace App\Content\Domain\Entity;

use App\Content\Domain\Enum\MediaType;
use App\Content\Domain\Enum\MediaVisibility;
use App\Content\Infrastructure\Repository\MediaRepository;
use App\Shared\Domain\Traits\Blamable;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\Table(name: 'media')]
#[ORM\UniqueConstraint(name: 'uniq_media_storage_key', columns: ['storage_key'])]
#[ORM\Index(name: 'idx_media_legacy_filename', columns: ['legacy_filename'])]
#[ORM\Index(name: 'idx_media_filename', columns: ['filename'])]
#[ORM\HasLifecycleCallbacks]
class Media implements \Stringable
{
    use Blamable;

    private const array ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** File name below the old upload directory; only for media not yet moved to the storage (ADR 039, until v0.4). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    /** Path of the file below the media storage root, for example `originals/2026/11/<uuid>.png` (ADR 039). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $storageKey = null;

    /** SHA-256 of the stored file, written when the file is stored and compared by the integrity check. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksumSha256 = null;

    /** When the integrity check last hashed the stored file. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /** Name below the old upload directory before the move, so old URLs can still be resolved (until v0.4). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legacyFilename = null;

    #[ORM\Column(length: 16, enumType: MediaVisibility::class, options: ['default' => 'public'])]
    private MediaVisibility $visibility = MediaVisibility::Public;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    #[ORM\Column(nullable: true, enumType: MediaType::class)]
    private ?MediaType $type = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $altText = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @psalm-suppress PropertyNotSetInConstructor */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected ?User $updatedBy = null;

    /** The uploaded file until the upload handler has stored it in the media storage; not persisted. */
    #[Assert\NotNull(groups: ['media_create'])]
    #[Assert\File(
        maxSize: '10M',
        mimeTypes: self::ALLOWED_MIME_TYPES,
        groups: ['Default', 'media_create', 'media_update'],
    )]
    private ?File $file = null;

    public function __construct()
    {
        $this->createdAt = Clock::get()->now();
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->title ?? $this->originalFilename ?? sprintf('Media #%d', $this->id ?? 0);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function getType(): ?MediaType
    {
        return $this->type;
    }

    public function setType(?MediaType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getAltText(): ?string
    {
        return $this->altText;
    }

    public function setAltText(?string $altText): self
    {
        $this->altText = $altText;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): self
    {
        $this->file = $file;

        return $this;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function getChecksumSha256(): ?string
    {
        return $this->checksumSha256;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getLegacyFilename(): ?string
    {
        return $this->legacyFilename;
    }

    public function getVisibility(): MediaVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(MediaVisibility $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    /**
     * Points the media item at a file of the media storage. A media item moved from the old upload directory keeps
     * its old name as {@see getLegacyFilename()} and no longer has a {@see getFilename()}.
     */
    public function attachStoredFile(string $storageKey, int $size, string $checksumSha256, \DateTimeImmutable $verifiedAt): self
    {
        if (null === $this->storageKey && null !== $this->filename && '' !== $this->filename) {
            $this->legacyFilename = $this->filename;
        }

        $this->storageKey = $storageKey;
        $this->size = $size;
        $this->checksumSha256 = $checksumSha256;
        $this->verifiedAt = $verifiedAt;
        $this->filename = null;

        return $this;
    }

    public function markVerified(\DateTimeImmutable $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    /** Name of the file for operators: the storage key, or the old file name of a media item not yet moved. */
    public function getStoredName(): ?string
    {
        return $this->storageKey ?? $this->filename;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = Clock::get()->now();
    }
}
