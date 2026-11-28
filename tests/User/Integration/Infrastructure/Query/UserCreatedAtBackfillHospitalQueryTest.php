<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Query;

use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Query\UserCreatedAtBackfillHospitalQuery;

/**
 * Hospitals that `app:user:backfill-created-at` may take a date from: owned ones only. How the date is picked from them
 * is in `UserCreatedAtBackfillDecisionTest`.
 */
final class UserCreatedAtBackfillHospitalQueryTest extends DatabaseKernelTestCase
{
    public function testListsOwnedHospitalsAndIgnoresAccessGrants(): void
    {
        $alice = UserFactory::createOne(['username' => 'alice']);
        $bob = UserFactory::createOne(['username' => 'bob']);
        $owned = HospitalFactory::createOne(['name' => 'Owned Klinik', 'owner' => $alice]);
        $granted = HospitalFactory::createOne(['name' => 'Grant Klinik', 'owner' => $bob]);
        HospitalAccessGrantFactory::createOne(['user' => $alice, 'hospital' => $granted]);

        $byUser = self::getContainer()->get(UserCreatedAtBackfillHospitalQuery::class)->ownedHospitalsByUserId();

        self::assertSame([
            (int) $alice->getId() => [['id' => (int) $owned->getId(), 'name' => 'Owned Klinik']],
            (int) $bob->getId() => [['id' => (int) $granted->getId(), 'name' => 'Grant Klinik']],
        ], $byUser);
    }
}
