<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Query;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Query\ListImportsQuery;
use App\Import\UI\Http\DTO\ListImportQueryParametersDTO;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListImportsQueryTest extends KernelTestCase
{
    use Factories;

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
        $paginator = $this->query->getPaginator($owner, new ListImportQueryParametersDTO());

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

        $paginator = $this->query->getPaginator($admin, new ListImportQueryParametersDTO());
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

        $paginator = $this->query->getPaginator($owner, new ListImportQueryParametersDTO(hospitalId: $hospitalA->_real()->getId()));
        $names = $this->extractImportNames($paginator);

        self::assertSame(['Import A'], $names);
    }

    public function testInvalidHospitalFilterIsIgnored(): void
    {
        [$owner, $foreignImport] = $this->seedTwoOwnersWithImports();
        $paginator = $this->query->getPaginator($owner, new ListImportQueryParametersDTO(hospitalId: $foreignImport->getHospital()?->getId()));

        $names = $this->extractImportNames($paginator);
        self::assertContains('Own Import', $names);
        self::assertNotContains($foreignImport->getName(), $names);
    }

    public function testOwnerFilterRestrictsResults(): void
    {
        [$owner] = $this->seedTwoOwnersWithImports();
        $paginator = $this->query->getPaginator($owner, new ListImportQueryParametersDTO(ownerId: $owner->getId()));

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

        $paginator = $this->query->getPaginator($owner, new ListImportQueryParametersDTO(
            createdFrom: '2025-03-10',
            createdUntil: '2025-03-11',
        ));

        $names = $this->extractImportNames($paginator);
        self::assertSame(['In range'], $names);
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

        $paginator = $this->query->getPaginator($owner, new ListImportQueryParametersDTO(
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

        $paginator = $this->query->getPaginator($owner, new ListImportQueryParametersDTO(
            orderBy: 'asc',
            sortBy: 'createdAt',
        ));

        $names = $this->extractImportNames($paginator);
        self::assertSame(['Earlier', 'Later'], $names);
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
        ], $extra))->_real();
    }

    /**
     * @return list<string>
     */
    private function extractImportNames(\App\Shared\Infrastructure\Pagination\Paginator $paginator): array
    {
        $names = [];
        foreach ($paginator->getResults() as $import) {
            if ($import instanceof Import) {
                $names[] = (string) $import->getName();
            }
        }

        return $names;
    }
}
