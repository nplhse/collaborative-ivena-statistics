<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<IndicationGroup>
 */
final class IndicationGroupFactory extends PersistentProxyObjectFactory
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
