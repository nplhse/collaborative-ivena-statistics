<?php

declare(strict_types=1);

namespace App\Encounter\Application\Curation\Package;

use App\Encounter\Domain\Enum\MtsCatalogKind;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Another spelling of a curated entry of the same kind (ADR 032). A former merge travels as its alias only, so the old
 * code resolves the same way on the target.
 */
final readonly class AliasData
{
    public function __construct(
        #[Assert\Choice(callback: [self::class, 'kinds'])]
        public string $kind,
        #[Assert\Regex(pattern: CurationPackageFormat::CODE_PATTERN, message: 'Expected a catalog code (a–z, 0–9, _; at most 64 characters).')]
        public string $code,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $label,
        #[SerializedName('target_code')]
        #[Assert\Regex(pattern: CurationPackageFormat::CODE_PATTERN, message: 'Expected a catalog code (a–z, 0–9, _; at most 64 characters).')]
        public string $targetCode,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue Choice constraint callback
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return array_map(static fn (MtsCatalogKind $kind): string => $kind->value, MtsCatalogKind::cases());
    }
}
