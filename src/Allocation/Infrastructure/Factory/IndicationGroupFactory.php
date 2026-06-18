<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<IndicationGroup>
 */
final class IndicationGroupFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return IndicationGroup::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'createdAt' => new \DateTimeImmutable(),
            'createdBy' => UserFactory::randomOrCreate(),
            'name' => self::faker()->words(2, true),
            'description' => self::faker()->optional()->sentence(),
            'category' => self::faker()->optional()->word(),
            'sortOrder' => self::faker()->optional()->numberBetween(1, 100),
        ];
    }
}
