<?php

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\SecondaryTransport;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<SecondaryTransport>
 */
final class SecondaryTransportFactory extends PersistentProxyObjectFactory
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
            'Kapazitätsengpass',
            'Spezialversorgung',
            'Verlegung auf Wunsch',
            'Weitere Diagnostik',
            'Rehabilitation',
        ];

        return [
            'createdAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime()),
            'createdBy' => UserFactory::random(),
            'name' => self::faker()->randomElement($names),
        ];
    }
}
