<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Query;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Explore\UserHospitalRelation;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Query\UserHospitalRelationsQuery;

final class UserHospitalRelationsQueryTest extends DatabaseKernelTestCase
{
    public function testForUserIdsIncludesLocationSizeAndTier(): void
    {
        $user = UserFactory::createOne();
        $userId = $user->getId();
        self::assertNotNull($userId);

        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatchArea = DispatchAreaFactory::createOne([
            'name' => 'Frankfurt',
            'state' => $state,
        ]);
        $owned = HospitalFactory::createOne([
            'name' => 'Owned Klinik',
            'owner' => $user,
            'dispatchArea' => $dispatchArea,
            'state' => $state,
            'location' => HospitalLocation::URBAN,
            'tier' => HospitalTier::EXTENDED,
            'size' => HospitalSize::MEDIUM,
            'beds' => 220,
        ]);
        HospitalAccessGrantFactory::createOne([
            'user' => $user,
            'hospital' => HospitalFactory::createOne([
                'name' => 'Grant Klinik',
                'owner' => UserFactory::createOne(),
            ]),
        ]);

        $query = self::getContainer()->get(UserHospitalRelationsQuery::class);
        $summaries = $query->forUserIds([$userId])[$userId] ?? [];

        $byName = [];
        foreach ($summaries as $summary) {
            $byName[$summary->name] = $summary;
        }

        self::assertArrayHasKey('Owned Klinik', $byName);
        self::assertArrayHasKey('Grant Klinik', $byName);
        self::assertSame($owned->getPublicIdString(), $byName['Owned Klinik']->publicId);
        self::assertSame(UserHospitalRelation::OWNER, $byName['Owned Klinik']->relation);
        self::assertSame('Frankfurt', $byName['Owned Klinik']->dispatchAreaName);
        self::assertSame('Hessen', $byName['Owned Klinik']->stateName);
        self::assertSame('Urban', $byName['Owned Klinik']->location);
        self::assertSame('Extended', $byName['Owned Klinik']->tier);
        self::assertSame('Medium', $byName['Owned Klinik']->size);
        self::assertSame(220, $byName['Owned Klinik']->beds);
        self::assertSame(UserHospitalRelation::ACCESS, $byName['Grant Klinik']->relation);
    }
}
