<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

use App\Encounter\Domain\Enum\MtsCatalogSource;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A curated MTS diagram or indicator (ADR 032). The code is the stable key; provisional and merged entries are never
 * part of a package.
 */
final readonly class CatalogEntryData
{
    public function __construct(
        #[Assert\Regex(pattern: CurationPackageFormat::CODE_PATTERN, message: 'Expected a catalog code (a–z, 0–9, _; at most 64 characters).')]
        public string $code,
        #[SerializedName('german_name')]
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $germanName,
        #[SerializedName('english_name')]
        #[Assert\Length(min: 1, max: 255)]
        public ?string $englishName,
        #[Assert\Choice(callback: [self::class, 'sources'])]
        public string $source,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue Choice constraint callback
     *
     * @return list<string>
     */
    public static function sources(): array
    {
        return array_map(static fn (MtsCatalogSource $source): string => $source->value, MtsCatalogSource::cases());
    }
}
