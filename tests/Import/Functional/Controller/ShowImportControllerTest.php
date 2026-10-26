<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowImportControllerTest extends WebTestCase
{
    use Factories;

    public function testShowDisplaysImportDetails(): void
    {
        $client = self::createClient();
        [$owner, $importId, $name, $hospitalName] = $this->createImportWithRelations();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $importId));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#import-id');
        self::assertSelectorTextContains('#import-id', '#'.$importId);
        self::assertSelectorTextContains('body', $name);
        self::assertSelectorTextContains('body', $hospitalName);
        self::assertSelectorTextContains('.datagrid', 'CSV');
        self::assertSelectorTextContains('.datagrid', 'Mime Type');
        self::assertSelectorTextContains('.datagrid', 'KB');
        self::assertSelectorTextContains('body', 'Pending');
        self::assertSelectorTextContains('body', 'Allocations');
        self::assertSelectorTextContains('#import-imported-records', '4 Allocations imported');
        self::assertSelectorTextContains('body', 'Deduplicated rows (total)');
        self::assertSelectorTextContains('body', '0 ms');
        self::assertSelectorTextContains('body', '1');
        self::assertSelectorTextContains('body', 'All allocations, projections, and related data will be removed.');
        self::assertSelectorTextContains('[data-testid="import-data-coverage-fallback"]', 'calculated once the import has finished');

        $allocationHref = $client->getCrawler()->filter('a[href*="/explore/allocation"]')->attr('href');
        $mciHref = $client->getCrawler()->filter('a[href*="/explore/mci_case"]')->attr('href');
        self::assertIsString($allocationHref);
        self::assertIsString($mciHref);
        self::assertStringContainsString('importId='.$importId, $allocationHref);
        self::assertStringContainsString('importId='.$importId, $mciHref);
    }

    public function testClosureImportHidesAllocationLinks(): void
    {
        $client = self::createClient();
        [$owner, $importId, , , $hospitalId] = $this->createImportWithRelations(
            ImportType::CLOSURE,
            ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA'],
            382,
            'Closure upload',
        );

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $importId));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Closure list (beta)');
        self::assertSelectorTextContains('#import-imported-records', '382 Closures imported');
        self::assertSelectorTextNotContains('body', 'Deduplicated');
        self::assertSelectorTextContains('body', 'All closure intervals and related data will be removed.');
        self::assertSelectorNotExists('a[href*="/explore/allocation"]');
        self::assertSelectorNotExists('a[href*="/explore/mci_case"]');

        $analyticsHref = $client->getCrawler()->filter('a[href*="/statistics/closure-analytics"]')->attr('href');
        self::assertIsString($analyticsHref);
        self::assertStringContainsString('closureHospitals', $analyticsHref);
        self::assertStringContainsString((string) $hospitalId, $analyticsHref);
    }

    public function testCompletedAllocationImportShowsCoverageAndPotentialGaps(): void
    {
        $client = self::createClient();
        $days = [];
        foreach (range(1, 30) as $day) {
            if (!\in_array($day, [12, 13, 20], true)) {
                $days[\sprintf('2026-09-%02d', $day)] = 10 + $day;
            }
        }
        [$owner, $importId] = $this->createImportWithRelations(
            status: ImportStatus::COMPLETED,
            coverage: new ImportDataCoverage(
                array_sum($days),
                new \DateTimeImmutable('2026-09-01 00:05:00'),
                new \DateTimeImmutable('2026-09-30 23:55:00'),
                $days,
            ),
        );

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $importId));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="import-data-coverage-period"]', '01.09.2026 – 30.09.2026');
        self::assertSelectorTextContains('[data-testid="import-data-coverage-period"]', '30 days');
        self::assertSelectorTextContains('[data-testid="import-data-coverage-metrics"]', '01.09.2026 00:05');
        self::assertSelectorTextContains('[data-testid="import-data-coverage-days"]', '27 of 30 days');
        self::assertSelectorTextContains('[data-testid="import-data-coverage-gaps"]', 'Potential gaps');
        $gaps = $client->getCrawler()->filter('[data-testid="import-data-coverage-gap"]');
        self::assertCount(2, $gaps);
        self::assertSame(['12.09.2026', '13.09.2026', '2 days'], $gaps->eq(0)->filter('td')->each(static fn ($cell): string => trim($cell->text())));
        self::assertSame(['20.09.2026', '20.09.2026', '1 day'], $gaps->eq(1)->filter('td')->each(static fn ($cell): string => trim($cell->text())));
        self::assertSelectorTextContains('[data-testid="import-data-coverage-gaps"] .badge', '2 gaps · 3 days without data');
        self::assertSelectorNotExists('.import-coverage-gaps-details');
        self::assertSelectorExists('[data-testid="import-data-coverage-heatmap"]');
        self::assertCount(3, $client->getCrawler()->filter('.import-coverage-calendar .is-gap'));
        self::assertSelectorExists('.import-coverage-calendar [title="12.09.2026: no records"]');
        self::assertSelectorExists('.import-coverage-calendar [title="01.09.2026: 11 records"]');
        self::assertSelectorTextContains('.import-coverage-calendar .import-coverage-week .import-coverage-month', 'Sep');
        self::assertSelectorTextContains('.import-coverage-weekdays', 'Mo');
    }

    public function testMoreThanTenGapsAreCollapsed(): void
    {
        $client = self::createClient();
        $days = [];
        foreach (range(1, 31, 2) as $day) {
            $days[\sprintf('2026-10-%02d', $day)] = 1;
        }
        [$owner, $importId] = $this->createImportWithRelations(
            status: ImportStatus::COMPLETED,
            coverage: new ImportDataCoverage(
                \count($days),
                new \DateTimeImmutable('2026-10-01 08:00:00'),
                new \DateTimeImmutable('2026-10-31 08:00:00'),
                $days,
            ),
        );

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $importId));

        self::assertResponseIsSuccessful();
        self::assertCount(15, $client->getCrawler()->filter('[data-testid="import-data-coverage-gap"]'));
        self::assertCount(5, $client->getCrawler()->filter('.import-coverage-gaps-details [data-testid="import-data-coverage-gap"]'));
        self::assertSelectorTextContains('.import-coverage-gaps-details summary', 'Show all 15 gaps');
    }

    public function testCompletedClosureImportShowsCoverageWithoutGapWarnings(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportWithRelations(
            ImportType::CLOSURE,
            ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA'],
            2,
            'Closure upload',
            ImportStatus::COMPLETED,
            new ImportDataCoverage(
                2,
                new \DateTimeImmutable('2026-09-01 10:00:00'),
                new \DateTimeImmutable('2026-09-10 12:00:00'),
                ['2026-09-01' => 1, '2026-09-10' => 1],
            ),
        );

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $importId));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="import-data-coverage-days"]', '2 of 10 days');
        self::assertSelectorTextContains('[data-testid="import-data-coverage"]', 'no gaps are flagged');
        self::assertSelectorNotExists('[data-testid="import-data-coverage-gaps"]');
        self::assertSelectorNotExists('.import-coverage-calendar .is-gap');
        self::assertSelectorExists('.import-coverage-calendar [title="05.09.2026: no active closure"]');
    }

    public function testFinishedImportWithoutStoredCoverageShowsAFallback(): void
    {
        $client = self::createClient();
        [$owner, $importId] = $this->createImportWithRelations(status: ImportStatus::COMPLETED);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $importId));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="import-data-coverage-fallback"]', 'has not been calculated for this import yet');
        self::assertSelectorNotExists('[data-testid="import-data-coverage-heatmap"]');
    }

    public function testForeignImportIsNotAccessible(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne([
            'username' => 'owner-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $intruder = UserFactory::createOne([
            'username' => 'intruder-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $createdBy = UserFactory::createOne(['username' => 'creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne();

        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $import = ImportFactory::createOne([
            'name' => 'Protected Import',
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'filePath' => '/tmp/protected.csv',
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 12,
            'rowCount' => 5,
            'rowsPassed' => 4,
            'rowsRejected' => 1,
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ]);

        $client->loginUser($intruder);
        $client->request(Request::METHOD_GET, \sprintf('/import/%d', $import->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param list<string> $roles
     *
     * @return array{0: User, 1: int, 2: string, 3: string, 4: int}
     */
    private function createImportWithRelations(
        ImportType $type = ImportType::ALLOCATION,
        array $roles = ['ROLE_USER', 'ROLE_PARTICIPANT'],
        int $rowsPassed = 4,
        string $name = 'Test Allocations',
        ImportStatus $status = ImportStatus::PENDING,
        ?ImportDataCoverage $coverage = null,
    ): array {
        $owner = UserFactory::createOne([
            'username' => 'owner-user',
            'roles' => $roles,
        ]);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $hospitalName = $hospital->getName();
        $hospitalId = $hospital->getId();
        self::assertNotNull($hospitalId);
        $target = 'dummy/path';

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var User $freshOwner */
        $freshOwner = $em->getRepository(User::class)->find($owner->getId());
        self::assertNotNull($freshOwner);

        $import = new Import()
            ->setName($name)
            ->setHospital($hospital)
            ->setCreatedBy($freshOwner)
            ->setType($type)
            ->setStatus($status)
            ->setFilePath($target)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(\filesize($target) ?: 0)
            ->setFileChecksum('abc123checksum')
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(5)
            ->setRowsPassed($rowsPassed)
            ->setRowsRejected(1);
        $import->applyDataCoverage($coverage);

        $em->persist($import);
        $em->flush();

        return [$freshOwner, (int) $import->getId(), $name, $hospitalName, $hospitalId];
    }
}
