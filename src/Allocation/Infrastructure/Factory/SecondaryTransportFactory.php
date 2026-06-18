<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\SecondaryTransport;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<SecondaryTransport>
 */
final class SecondaryTransportFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return SecondaryTransport::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        $names = [
            'Diagnostik',
            'Intensivstation',
            'Intervention',
            'OP',
            'Sekundärverlegung',
            'Sonstiger Einsatz',
            'Weaning',
            'Kapazitätsengpass',
        ];

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
            'createdBy' => UserFactory::random(),
            'name' => self::faker()->randomElement($names),
        ];
    }
}
