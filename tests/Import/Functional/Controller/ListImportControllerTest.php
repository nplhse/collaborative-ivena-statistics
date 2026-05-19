<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\UI\Http\DTO\ListImportQueryParametersDTO;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ListImportControllerTest extends WebTestCase
{
    use HasBrowser;
    use Factories;
    use ResetDatabase;

    public function testTableWithResultsIsShown(): void
    {
        [$owner] = $this->seedImportsWithFactory(10);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import')
            ->assertSuccessful()
            ->assertSeeElement('table.table thead')
            ->assertSeeElement('table.table tbody')
            ->use(function (Crawler $crawler): void {
                $rows = $crawler->filter('table.table tbody tr');
                self::assertCount(10, $rows, 'We should see 10 rows of results.');

                $firstTds = $rows->eq(0)->filter('td');
                self::assertGreaterThanOrEqual(6, $firstTds->count());
                $nameText = \trim($firstTds->eq(1)->text(''));
                self::assertNotEmpty($nameText);
            })
            ->assertSeeElement('#result-count')
            ->use(function (Crawler $crawler): void {
                $txt = \preg_replace('/\s+/', ' ', $crawler->filter('#result-count')->text(''));
                self::assertStringContainsString('1', $txt);
                self::assertStringContainsString('10', $txt);
            });
    }

    public function testTableCanBeSortedByNameDesc(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('ACME Hospital Import', $hospital, $createdBy, ['filePath' => '/tmp/acme.csv']);
        $this->createImportForList('XYZ Hospital Import', $hospital, $createdBy, ['filePath' => '/tmp/xyz.csv']);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import?sortBy=name&orderBy=desc')
            ->assertSuccessful()
            ->use(function (Crawler $crawler): void {
                $rows = $crawler->filter('table.table tbody tr');
                self::assertGreaterThanOrEqual(2, $rows->count());
                $firstName = \trim($rows->eq(0)->filter('td')->eq(1)->text(''));
                self::assertSame('XYZ Hospital Import', $firstName);
            });
    }

    public function testTableCanBePaginated(): void
    {
        [$owner] = $this->seedImportsWithFactory(35);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import?page=2')
            ->assertSuccessful()
            ->use(function (Crawler $crawler): void {
                $rows = $crawler->filter('table.table tbody tr');
                self::assertCount(10, $rows, 'We should see 10 rows of results.');

                $txt = \preg_replace('/\s+/', ' ', $crawler->filter('#result-count')->text(''));
                self::assertStringContainsString('26', $txt);
                self::assertStringContainsString('35', $txt);
            });
    }

    public function testEmptyFilterQueryParamsAreAccepted(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('Filtered Import', $hospital, $createdBy, ['filePath' => '/tmp/filtered.csv']);

        $this->browser()
            ->actingAs($owner)
            ->visit(\sprintf(
                '/import?hospitalId=%d&ownerId=&status=&createdFrom=&createdUntil=',
                $hospital->getId(),
            ))
            ->assertSuccessful()
            ->assertSee('Filtered Import')
            ->assertSeeElement('.alert-info .badge');
    }

    public function testDefaultDateFiltersAreApplied(): void
    {
        [$owner] = $this->seedImportsWithFactory(1);

        $crawler = $this->browser()
            ->actingAs($owner)
            ->visit('/import')
            ->assertSuccessful()
            ->use(function (Crawler $crawler): void {
                self::assertSame(
                    ListImportQueryParametersDTO::DEFAULT_CREATED_FROM,
                    $crawler->filter('#import-filter-created-from')->attr('value'),
                );
                self::assertSame(
                    new \DateTimeImmutable('today')->format('Y-m-d'),
                    $crawler->filter('#import-filter-created-until')->attr('value'),
                );
            })
            ->crawler();

        self::assertNotNull($crawler);
    }

    public function testActiveFilterBadgesShowHospitalName(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('Hospital Filtered Import', $hospital, $createdBy, ['filePath' => '/tmp/hospital-filtered.csv']);

        $this->browser()
            ->actingAs($owner)
            ->visit(\sprintf('/import?hospitalId=%d', $hospital->getId()))
            ->assertSuccessful()
            ->assertSee('Active filters')
            ->assertSee('St. Test Hospital');
    }

    public function testParticipantDoesNotSeeForeignHospitalImports(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();
        $other = UserFactory::createOne(['username' => 'other-'.bin2hex(random_bytes(3))]);
        $foreignHospital = HospitalFactory::createOne([
            'name' => 'Foreign Hospital',
            'owner' => $other,
            'createdBy' => $createdBy,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        $this->createImportForList('Visible Import', $hospital, $createdBy, ['filePath' => '/tmp/visible.csv']);
        $this->createImportForList('Secret Foreign Import', $foreignHospital, $createdBy, ['filePath' => '/tmp/secret.csv']);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import')
            ->assertSuccessful()
            ->assertSee('Visible Import')
            ->assertNotSee('Secret Foreign Import');
    }

    public function testAdminSeesImportsFromAllOwners(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();
        $other = UserFactory::createOne(['username' => 'other-'.bin2hex(random_bytes(3))]);
        $foreignHospital = HospitalFactory::createOne([
            'name' => 'Foreign Hospital',
            'owner' => $other,
            'createdBy' => $createdBy,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        $this->createImportForList('Own Import', $hospital, $createdBy, ['filePath' => '/tmp/own.csv']);
        $this->createImportForList('Foreign Import', $foreignHospital, $createdBy, ['filePath' => '/tmp/foreign.csv']);

        $admin = UserFactory::createOne([
            'roles' => ['ROLE_ADMIN'],
            'username' => 'admin-'.bin2hex(random_bytes(4)),
        ]);

        $this->browser()
            ->actingAs($admin)
            ->visit('/import')
            ->assertSuccessful()
            ->assertSee('Own Import')
            ->assertSee('Foreign Import');
    }

    public function testStatusFilterShowsOnlyMatchingImports(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('Completed Import', $hospital, $createdBy, [
            'status' => ImportStatus::COMPLETED,
            'filePath' => '/tmp/completed.csv',
        ]);
        $this->createImportForList('Pending Import', $hospital, $createdBy, ['filePath' => '/tmp/pending.csv']);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import?status='.urlencode(ImportStatus::COMPLETED->value))
            ->assertSuccessful()
            ->assertSee('Completed Import')
            ->assertNotSee('Pending Import');
    }

    public function testDateRangeFilterWorks(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('In Range Import', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-04-01 10:00:00'),
            'filePath' => '/tmp/in-range.csv',
        ]);
        $this->createImportForList('Out Of Range Import', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-05-01 10:00:00'),
            'filePath' => '/tmp/out-of-range.csv',
        ]);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import?createdFrom=2025-04-01&createdUntil=2025-04-30')
            ->assertSuccessful()
            ->assertSee('In Range Import')
            ->assertNotSee('Out Of Range Import');
    }

    public function testSortByCreatedAtDesc(): void
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('Older Import', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-01-01 10:00:00'),
            'filePath' => '/tmp/older.csv',
        ]);
        $this->createImportForList('Newer Import', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
            'filePath' => '/tmp/newer.csv',
        ]);

        $this->browser()
            ->actingAs($owner)
            ->visit('/import?sortBy=createdAt&orderBy=desc')
            ->assertSuccessful()
            ->use(function (Crawler $crawler): void {
                $firstName = \trim($crawler->filter('table.table tbody tr')->eq(0)->filter('td')->eq(1)->text(''));
                self::assertSame('Newer Import', $firstName);
            });
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createImportForList(string $name, object $hospital, User $createdBy, array $overrides = []): void
    {
        ImportFactory::createOne(array_merge([
            'name' => $name,
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'createdAt' => new \DateTimeImmutable('now'),
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 12,
            'rowCount' => 5,
            'rowsPassed' => 4,
            'rowsRejected' => 1,
            'runCount' => 0,
            'runTime' => 0,
            'createdBy' => $createdBy,
        ], $overrides));
    }

    /**
     * @return array{
     *      0: User&\Zenstruck\Foundry\Persistence\Proxy<User>,
     *      1: \App\Allocation\Domain\Entity\Hospital&\Zenstruck\Foundry\Persistence\Proxy<\App\Allocation\Domain\Entity\Hospital>,
     *      2: User&\Zenstruck\Foundry\Persistence\Proxy<User>
     *  }
     */
    private function seedBaseActors(): array
    {
        $owner = UserFactory::createOne(['username' => 'owner-'.\bin2hex(random_bytes(3))]);
        $createdBy = UserFactory::findOrCreate(['username' => 'area-user']);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        return [$owner, $hospital, $createdBy];
    }

    /**
     * @return array{0:User}
     */
    private function seedImportsWithFactory(int $count): array
    {
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        if ($count > 0) {
            ImportFactory::createMany($count, fn (int $i): array => [
                'name' => \sprintf('Import %02d', $i + 1),
                'hospital' => $hospital,
                'type' => ImportType::ALLOCATION,
                'status' => ImportStatus::PENDING,
                'createdAt' => new \DateTimeImmutable('2024-01-01 12:00:00')->modify(\sprintf('+%d months', $i % 12)),
                'filePath' => \sprintf('/tmp/import_%02d.csv', $i + 1),
                'fileExtension' => 'csv',
                'fileMimeType' => 'text/csv',
                'fileSize' => 100 + $i,
                'fileChecksum' => \substr(\sha1((string) $i), 0, 12),
                'rowCount' => 5,
                'rowsPassed' => 4,
                'rowsRejected' => 1,
                'runCount' => 0,
                'runTime' => 0,
                'createdBy' => $createdBy,
            ]);
        }

        return [$owner];
    }
}
