<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Factory;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\User\Domain\Factory\UserFactory;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<HospitalAccessGrant>
 */
final class HospitalAccessGrantFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return HospitalAccessGrant::class;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function defaults(): array
    {
        return [
            'hospital' => HospitalFactory::new(),
            'user' => UserFactory::new(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]),
            'permissions' => HospitalPermissionMask::fromPermissions([
                HospitalPermission::View,
                HospitalPermission::Statistics,
            ]),
            'createdBy' => UserFactory::new()->withoutAutorefresh(),
        ];
    }

    /** @psalm-suppress MoreSpecificReturnType */
    #[\Override]
    protected function initialize(): static
    {
        /** @var static $factory */
        $factory = $this->afterInstantiate(static function (HospitalAccessGrant $grant): void {
            $hospital = $grant->getHospital();
            if ($hospital instanceof Hospital) {
                $hospital->addAccessGrant($grant);
            }
        });

        return $factory;
    }
}
