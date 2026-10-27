<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class PackageMeta
{
    /**
     * @param list<string>        $types
     * @param array<string, int>  $counts
     * @param list<PackageSource> $sources
     */
    public function __construct(
        #[Assert\EqualTo(ReferenceCatalogFormat::NAME)]
        public string $format,
        #[Assert\EqualTo(ReferenceCatalogFormat::VERSION)]
        public int $version,
        #[SerializedName('exported_at')]
        #[Assert\NotBlank]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM)]
        public string $exportedAt,
        public bool $complete,
        #[Assert\Count(min: 1)]
        #[Assert\Unique]
        #[Assert\All([new Assert\Choice(callback: [ReferenceCatalogType::class, 'values'])])]
        public array $types,
        #[Assert\All([new Assert\PositiveOrZero()])]
        public array $counts,
        #[Assert\Valid]
        public PackageChecksum $checksum,
        #[SerializedName('app_version')]
        #[Assert\Length(max: 255)]
        public ?string $appVersion = null,
        #[SerializedName('source_environment')]
        #[Assert\Length(max: 64)]
        public ?string $sourceEnvironment = null,
        #[Assert\Valid]
        public array $sources = [],
        public ?string $notes = null,
    ) {
    }
}
