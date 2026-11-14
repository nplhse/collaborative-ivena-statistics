<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class PackageMeta
{
    /**
     * @param array<string, int> $counts entries per section
     */
    public function __construct(
        #[Assert\EqualTo(CurationPackageFormat::NAME)]
        public string $format,
        #[Assert\EqualTo(CurationPackageFormat::VERSION)]
        public int $version,
        #[SerializedName('exported_at')]
        #[Assert\NotBlank]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM)]
        public string $exportedAt,
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
        public ?string $notes = null,
    ) {
    }
}
