<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use App\Allocation\Application\ReferenceCatalog\ReferenceNameAliasClassification;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AliasEntry
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        #[Assert\Choice(callback: [self::class, 'classifications'])]
        public string $classification,
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        public string $source,
        public ?string $note = null,
        #[SerializedName('valid_from')]
        #[Assert\Date]
        public ?string $validFrom = null,
        #[SerializedName('valid_to')]
        #[Assert\Date]
        public ?string $validTo = null,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue Choice constraint callback
     *
     * @return list<string>
     */
    public static function classifications(): array
    {
        return array_map(static fn (ReferenceNameAliasClassification $case): string => $case->value, ReferenceNameAliasClassification::cases());
    }
}
