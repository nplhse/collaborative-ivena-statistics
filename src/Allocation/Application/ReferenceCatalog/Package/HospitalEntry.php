<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Hospital reference attributes. Owner, participation and access grants are
 * environment state and not part of a package (ADR 020).
 */
final readonly class HospitalEntry implements CatalogEntry
{
    public function __construct(
        #[Assert\Uuid]
        public string $id,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $name,
        /** Public id of the state. */
        #[Assert\Uuid]
        public string $state,
        /** Public id of the dispatch area. */
        #[Assert\Uuid]
        public string $area,
        #[Assert\Choice(callback: [self::class, 'sizes'])]
        public string $size,
        #[Assert\Positive]
        public int $beds,
        #[Assert\Choice(callback: [self::class, 'locations'])]
        public string $location,
        #[Assert\Valid]
        public HospitalAddress $address,
        #[Assert\Choice(callback: [self::class, 'tiers'])]
        public ?string $tier = null,
        #[Assert\Valid]
        public ?HospitalCoordinates $coordinates = null,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue Choice constraint callback
     *
     * @return list<string>
     */
    public static function tiers(): array
    {
        return array_map(static fn (HospitalTier $case): string => $case->value, HospitalTier::cases());
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue Choice constraint callback
     *
     * @return list<string>
     */
    public static function sizes(): array
    {
        return array_map(static fn (HospitalSize $case): string => $case->value, HospitalSize::cases());
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue Choice constraint callback
     *
     * @return list<string>
     */
    public static function locations(): array
    {
        return array_map(static fn (HospitalLocation $case): string => $case->value, HospitalLocation::cases());
    }
}
