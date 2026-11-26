<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Application\Hospital\MyHospitals\CachedHospitalDataSnapshotReader;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * My Hospitals (#971) reads all hospitals together: the number of queries does not grow with the number of
 * hospitals, with or without cached snapshots. See docs/04-features/allocation/my-hospitals-overview.md.
 *
 * Every measurement boots a new kernel: its identity map is empty, as in production, and the test cache pool
 * (an array adapter, reset between requests) holds only what the measurement put there.
 */
#[ResetDatabase]
final class MyHospitalsQueryCountTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    /** The two snapshot queries (monthly volumes, imports) that a cache hit saves. */
    private const int SNAPSHOT_QUERIES = 2;

    /** @var array<int, list<int>> hospital ids per user id */
    private array $hospitalIds = [];

    #[\Override]
    protected function setUp(): void
    {
        self::createClient();
        self::mockTime(new \DateTimeImmutable('2026-03-20 10:00:00'));
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        IndicationRawFactory::createOne();
    }

    public function testQueryCountDoesNotGrowWithTheNumberOfHospitals(): void
    {
        // All data first: a measurement boots a new kernel, and the factories should not span kernels.
        $withTwo = $this->user();
        $withFive = $this->user();
        foreach ([true, false] as $owned) {
            $this->hospital($withTwo, $owned);
        }
        foreach ([true, true, true, false, false] as $owned) {
            $this->hospital($withFive, $owned);
        }

        $coldTwo = $this->queryCount($withTwo, cold: true);
        $coldFive = $this->queryCount($withFive, cold: true);
        $warmTwo = $this->queryCount($withTwo, cold: false);
        $warmFive = $this->queryCount($withFive, cold: false);

        self::assertSame($coldTwo, $coldFive, 'Without cache, 5 hospitals need as many queries as 2.');
        self::assertSame($warmTwo, $warmFive, 'With cache, 5 hospitals need as many queries as 2.');
        self::assertSame($coldFive - self::SNAPSHOT_QUERIES, $warmFive, 'Cached snapshots save the snapshot queries.');
    }

    private function queryCount(User $user, bool $cold): int
    {
        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->loginUser($user);
        if (!$cold) {
            // What the warm messages leave behind after imports and projection rebuilds.
            self::getContainer()->get(CachedHospitalDataSnapshotReader::class)->refresh($this->hospitalIds[(int) $user->getId()]);
        }
        self::getContainer()->get('doctrine.debug_data_holder')->reset();
        $client->enableProfiler();

        $client->request(Request::METHOD_GET, '/hospitals');

        self::assertResponseIsSuccessful();
        $profile = $client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector->getQueryCount();
    }

    private function user(): User
    {
        return UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'locale' => 'en']);
    }

    private function hospital(User $user, bool $owned): Hospital
    {
        $owner = $owned ? $user : UserFactory::createOne();
        // Its own state and dispatch area, so loading them per hospital would show up as extra queries.
        $state = StateFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);
        if (!$owned) {
            HospitalAccessGrantFactory::createOne([
                'hospital' => $hospital,
                'user' => $user,
                'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View, HospitalPermission::Statistics, HospitalPermission::Import]),
            ]);
        }

        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $owner,
            'status' => ImportStatus::COMPLETED,
            'type' => ImportType::ALLOCATION,
            'createdAt' => new \DateTimeImmutable('2026-03-10 10:00:00'),
        ]);
        AllocationFactory::createOne([
            'hospital' => $hospital,
            'import' => $import,
            'createdAt' => new \DateTimeImmutable('2026-02-14 12:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-02-14 12:20:00'),
        ]);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport((int) $import->getId());
        $this->hospitalIds[(int) $user->getId()][] = (int) $hospital->getId();

        return $hospital;
    }
}
