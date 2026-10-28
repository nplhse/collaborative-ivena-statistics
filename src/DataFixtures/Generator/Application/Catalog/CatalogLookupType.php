<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Catalog;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;

/**
 * The catalog types the generator resolves by name. The values are the keys of the mapping file.
 */
enum CatalogLookupType: string
{
    case Department = 'department';
    case Speciality = 'speciality';
    case Assignment = 'assignment';
    case Occasion = 'occasion';
    case Infection = 'infection';
    case SecondaryTransport = 'secondary_transport';

    /**
     * @return class-string
     */
    public function entityClass(): string
    {
        return match ($this) {
            self::Department => Department::class,
            self::Speciality => Speciality::class,
            self::Assignment => Assignment::class,
            self::Occasion => Occasion::class,
            self::Infection => Infection::class,
            self::SecondaryTransport => SecondaryTransport::class,
        };
    }

    /**
     * @param class-string $class
     */
    public static function forClass(string $class): self
    {
        foreach (self::cases() as $type) {
            if ($type->entityClass() === $class) {
                return $type;
            }
        }

        throw new \InvalidArgumentException(sprintf('%s is not a catalog type the generator resolves by name.', $class));
    }

    /**
     * The alias table of the type and its column for the owner row, if the type has aliases.
     *
     * @return array{table: string, foreignKey: string}|null
     */
    public function alias(): ?array
    {
        return match ($this) {
            self::Department => ['table' => 'department_alias', 'foreignKey' => 'department_id'],
            self::Speciality => ['table' => 'speciality_alias', 'foreignKey' => 'speciality_id'],
            default => null,
        };
    }
}
