<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Query;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Service\ImportListAccess;
use App\Import\Application\Service\ImportTypeExtensionRegistry;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Query\ListImportsQuery;
use App\Import\UI\Http\DTO\ListImportQueryParametersDTO;
use App\Tests\Import\Doubles\Service\StubImportTypeExtension;
use App\Tests\Support\Pagination\PaginatesQueries;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\Pagination\NumberedPaginationInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListImportsQueryTest extends KernelTestCase
{
    use Factories;
    use PaginatesQueries;

    private ListImportsQuery $query;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(ListImportsQuery::class);
    }

    public function testParticipantSeesOnlyOwnHospitalImports(): void
    {
        [$owner, $foreignImport] = $this->seedTwoOwnersWithImports();
        $paginator = $this->paginateImports($owner, new ListImportQueryParametersDTO());

        $names = $this->extractImportNames($paginator);
        self::assertContains('Own Import', $names);
        self::assertNotContains($foreignImport->getName(), $names);
    }

    public function testAdminSeesAllImports(): void
    {
        [, $foreignImport] = $this->seedTwoOwnersWithImports();
        $admin = UserFactory::createOne([
            'roles' => ['ROLE_ADMIN'],
            'username' => 'admin-'.bin2hex(random_bytes(4)),
        ]);

        $paginator = $this->paginateImports($admin, new ListImportQueryParametersDTO());
        $names = $this->extractImportNames($paginator);

        self::assertContains('Own Import', $names);
        self::assertContains($foreignImport->getName(), $names);
    }

    public function testHospitalFilterRestrictsResults(): void
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospitalA = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Hospital A', 'state' => $state, 'dispatchArea' => $dispatch, 'createdBy' => $createdBy]);
        $hospitalB = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Hospital B', 'state' => $state, 'dispatchArea' => $dispatch, 'createdBy' => $createdBy]);

        $this->createImport('Import A', $hospitalA, $createdBy);
        $this->createImport('Import B', $hospitalB, $createdBy);

        $paginator = $this->paginateImports($owner, new ListImportQueryParametersDTO(hospitalId: $hospitalA->getId()));
        $names = $this->extractImportNames($paginator);

        self::assertSame(['Import A'], $names);
    }

    public function testClosureImportsAreListedLikeOtherImports(): void
    {
        $owner = UserFactory::createOne([
            'username' => 'owner-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'name' => 'Closure Hospital',
            'state' => $state,
            'dispatchArea' => $dispatch,
            'createdBy' => $owner,
        ]);

        $this->createImport('Allocation import', $hospital, $owner);
        $this->createImport('Closure import', $hospital, $owner, ['type' => ImportType::CLOSURE]);

        // Closure imports left the beta (#1350): no extra role hides them.
        $visible = $this->extractImportNames($this->paginateImports($owner, new ListImportQueryParametersDTO()));
        self::assertContains('Allocation import', $visible);
        self::assertContains('Closure import', $visible);
    }

    public function testInvalidHospitalFilterIsIgnored(): void
    {
        [$owner, $foreignImport] = $this->seedTwoOwnersWithImports();
        $paginator = $this->paginateImports($owner, new ListImportQueryParametersDTO(hospitalId: $foreignImport->getHospital()?->getId()));

        $names = $this->extractImportNames($paginator);
        self::assertContains('Own Import', $names);
        self::assertNotContains($foreignImport->getName(), $names);
    }

    public function testOwnerFilterRestrictsResults(): void
    {
        [$owner] = $this->seedTwoOwnersWithImports();
        $paginator = $this->paginateImports($owner, new ListImportQueryParametersDTO(ownerId: $owner->getId()));

        $names = $this->extractImportNames($paginator);
        self::assertSame(['Own Import'], $names);
    }

    public function testDateRangeFilterIncludesFullDays(): void
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        $this->createImport('In range', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-03-10 23:59:00'),
        ]);
        $this->createImport('Out of range', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-03-12 00:00:00'),
        ]);

        $paginator = $this->paginateImports($owner, new ListImportQueryParametersDTO(
            createdFrom: '2025-03-10',
            createdUntil: '2025-03-11',
        ));

        $names = $this->extractImportNames($paginator);
        self::assertSame(['In range'], $names);
    }

    public function testTypeFilterShowsOnlyThatType(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedOwnHospital();
        $this->createImport('Allocation import', $hospital, $createdBy);
        $this->createImport('Closure import', $hospital, $createdBy, ['type' => ImportType::CLOSURE]);

        $names = $this->extractImportNames($this->paginateImports($owner, new ListImportQueryParametersDTO(
            type: ImportType::CLOSURE->value,
        )));

        self::assertSame(['Closure import'], $names);
    }

    /**
     * Data period 2025-03-10 – 2025-03-20 overlaps the selected days unless it ends before them or starts after them.
     */
    public function testDataPeriodFilterMatchesOverlappingExportPeriods(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedOwnHospital();
        $this->createImport('Before', $hospital, $createdBy, $this->period('2025-03-01 00:00', '2025-03-09 23:59'));
        $this->createImport('Ends on first day', $hospital, $createdBy, $this->period('2025-03-01 00:00', '2025-03-10 08:00'));
        $this->createImport('Inside', $hospital, $createdBy, $this->period('2025-03-12 00:00', '2025-03-15 23:59'));
        $this->createImport('Starts on last day', $hospital, $createdBy, $this->period('2025-03-20 23:00', '2025-03-31 23:59'));
        $this->createImport('After', $hospital, $createdBy, $this->period('2025-03-21 00:00', '2025-03-31 23:59'));
        $this->createImport('Without period', $hospital, $createdBy);

        $names = $this->extractImportNames($this->paginateImports($owner, new ListImportQueryParametersDTO(
            orderBy: 'asc',
            sortBy: 'name',
            periodFrom: '2025-03-10',
            periodUntil: '2025-03-20',
        )));

        self::assertSame(['Ends on first day', 'Inside', 'Starts on last day'], $names);
    }

    public function testDataPeriodFilterWorksWithOneBound(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedOwnHospital();
        $this->createImport('Old', $hospital, $createdBy, $this->period('2024-01-01 00:00', '2024-12-31 23:59'));
        $this->createImport('New', $hospital, $createdBy, $this->period('2025-01-01 00:00', '2025-01-31 23:59'));

        $from = $this->extractImportNames($this->paginateImports($owner, new ListImportQueryParametersDTO(periodFrom: '2025-01-01')));
        $until = $this->extractImportNames($this->paginateImports($owner, new ListImportQueryParametersDTO(periodUntil: '2024-12-31')));

        self::assertSame(['New'], $from);
        self::assertSame(['Old'], $until);
    }

    public function testTypeChoicesFollowTheListVisibility(): void
    {
        [$owner] = $this->seedOwnHospital();
        $withoutAccess = UserFactory::createOne(['username' => 'nobody-'.bin2hex(random_bytes(4))]);

        $withoutEncounter = $this->queryWithEncounterHospitals([]);
        $withEncounter = $this->queryWithEncounterHospitals([42]);

        self::assertSame([ImportType::ALLOCATION, ImportType::CLOSURE], $withoutEncounter->visibleTypes($owner));
        self::assertSame([ImportType::ALLOCATION, ImportType::CLOSURE, ImportType::ENCOUNTER], $withEncounter->visibleTypes($owner));
        self::assertSame([], $withoutEncounter->visibleTypes($withoutAccess));
    }

    public function testStatusFilterUsesEnumValue(): void
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        $this->createImport('Completed import', $hospital, $createdBy, ['status' => ImportStatus::COMPLETED]);
        $this->createImport('Pending import', $hospital, $createdBy, ['status' => ImportStatus::PENDING]);

        $paginator = $this->paginateImports($owner, new ListImportQueryParametersDTO(
            status: ImportStatus::COMPLETED->value,
        ));

        $names = $this->extractImportNames($paginator);
        self::assertSame(['Completed import'], $names);
    }

    public function testSortByCreatedAtAsc(): void
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        $this->createImport('Later', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-01-02 12:00:00'),
        ]);
        $this->createImport('Earlier', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-01-01 12:00:00'),
        ]);

        $paginator = $this->paginateImports($owner, new ListImportQueryParametersDTO(
            orderBy: 'asc',
            sortBy: 'createdAt',
        ));

        $names = $this->extractImportNames($paginator);
        self::assertSame(['Earlier', 'Later'], $names);
    }

    public function testUnknownSortByFallsBackToCreatedAt(): void
    {
        [$owner] = $this->seedTwoOwnersWithImports();

        $paginator = $this->paginateImports($owner, new ListImportQueryParametersDTO(sortBy: 'unknown'));

        self::assertGreaterThanOrEqual(1, $paginator->getTotalItems());
    }

    /**
     * @return array{0: User, 1: Import}
     */
    private function seedTwoOwnersWithImports(): array
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(4))]);
        $other = UserFactory::createOne(['username' => 'other-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $ownHospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
        $foreignHospital = HospitalFactory::createOne([
            'owner' => $other,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $this->createImport('Own Import', $ownHospital, $createdBy);
        $foreignImport = $this->createImport('Foreign Import', $foreignHospital, $createdBy);

        return [$owner, $foreignImport];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function createImport(string $name, object $hospital, User $createdBy, array $extra = []): Import
    {
        return ImportFactory::createOne(array_merge([
            'name' => $name,
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => '/tmp/'.$name.'.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 12,
            'rowsPassed' => 4,
            'rowsRejected' => 1,
            'runCount' => 0,
            'runTime' => 0,
            'createdAt' => new \DateTimeImmutable('now'),
            'createdBy' => $createdBy,
        ], $extra));
    }

    /**
     * @param NumberedPaginationInterface<mixed> $paginator
     *
     * @return list<string>
     */
    private function extractImportNames(NumberedPaginationInterface $paginator): array
    {
        $names = [];
        foreach ($paginator->getItems() as $import) {
            if ($import instanceof Import) {
                $names[] = (string) $import->getName();
            }
        }

        return $names;
    }

    /**
     * @return NumberedPaginationInterface<mixed>
     */
    private function paginateImports(User $user, ListImportQueryParametersDTO $query): NumberedPaginationInterface
    {
        return $this->paginateQuery($this->query->listQuery($user, $query));
    }

    /**
     * @return array{User, object, User}
     */
    private function seedOwnHospital(): array
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        return [$owner, $hospital, $createdBy];
    }

    /**
     * @return array{exportStartsAt: \DateTimeImmutable, exportEndsAt: \DateTimeImmutable}
     */
    private function period(string $startsAt, string $endsAt): array
    {
        return ['exportStartsAt' => new \DateTimeImmutable($startsAt), 'exportEndsAt' => new \DateTimeImmutable($endsAt)];
    }

    /**
     * @param list<int> $encounterHospitalIds
     */
    private function queryWithEncounterHospitals(array $encounterHospitalIds): ListImportsQuery
    {
        $container = self::getContainer();

        return new ListImportsQuery(
            $container->get(EntityManagerInterface::class),
            $container->get(ImportListAccess::class),
            new ImportTypeExtensionRegistry([new StubImportTypeExtension(true, $encounterHospitalIds)]),
        );
    }
}
