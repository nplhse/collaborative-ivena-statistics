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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListImportControllerTest extends WebTestCase
{
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        $client = self::createClient();
        [$owner] = $this->seedImportsWithFactory(10);

        $crawler = $this->requestAsUser($client, $owner, '/import');

        self::assertGreaterThan(0, $crawler->filter('table.table thead')->count());
        self::assertGreaterThan(0, $crawler->filter('table.table tbody')->count());

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');

        $firstTds = $rows->eq(0)->filter('td');
        self::assertGreaterThanOrEqual(6, $firstTds->count());
        $nameText = \trim($firstTds->eq(1)->text(''));
        self::assertNotEmpty($nameText);

        self::assertGreaterThan(0, $crawler->filter('#result-count')->count());
        $txt = \preg_replace('/\s+/', ' ', $crawler->filter('#result-count')->text(''));
        self::assertStringContainsString('1', $txt);
        self::assertStringContainsString('10', $txt);
    }

    public function testTableCanBeSortedByNameDesc(): void
    {
        $client = self::createClient();
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('ACME Hospital Import', $hospital, $createdBy, ['filePath' => '/tmp/acme.csv']);
        $this->createImportForList('XYZ Hospital Import', $hospital, $createdBy, ['filePath' => '/tmp/xyz.csv']);

        $crawler = $this->requestAsUser($client, $owner, '/import?sortBy=name&orderBy=desc');

        $rows = $crawler->filter('table.table tbody tr');
        self::assertGreaterThanOrEqual(2, $rows->count());
        $firstName = \trim($rows->eq(0)->filter('td')->eq(1)->text(''));
        self::assertSame('XYZ Hospital Import', $firstName);
    }

    public function testTableCanBePaginated(): void
    {
        $client = self::createClient();
        [$owner] = $this->seedImportsWithFactory(35);

        $crawler = $this->requestAsUser($client, $owner, '/import?page=2');

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');

        $txt = \preg_replace('/\s+/', ' ', $crawler->filter('#result-count')->text(''));
        self::assertStringContainsString('26', $txt);
        self::assertStringContainsString('35', $txt);
    }

    public function testEmptyFilterQueryParamsAreAccepted(): void
    {
        $client = self::createClient();
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('Filtered Import', $hospital, $createdBy, ['filePath' => '/tmp/filtered.csv']);

        $crawler = $this->requestAsUser($client, $owner, \sprintf(
            '/import?hospitalId=%d&ownerId=&status=&createdFrom=&createdUntil=',
            $hospital->getId(),
        ));

        self::assertStringContainsString('Filtered Import', $crawler->text());
        self::assertGreaterThan(0, $crawler->filter('.alert-info .badge')->count());
    }

    public function testDefaultDateFiltersAreApplied(): void
    {
        $client = self::createClient();
        [$owner] = $this->seedImportsWithFactory(1);

        $crawler = $this->requestAsUser($client, $owner, '/import');

        self::assertSame(
            ListImportQueryParametersDTO::DEFAULT_CREATED_FROM,
            $crawler->filter('#import-filter-created-from')->attr('value'),
        );
        self::assertSame(
            new \DateTimeImmutable('today')->format('Y-m-d'),
            $crawler->filter('#import-filter-created-until')->attr('value'),
        );
    }

    public function testActiveFilterBadgesShowHospitalName(): void
    {
        $client = self::createClient();
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('Hospital Filtered Import', $hospital, $createdBy, ['filePath' => '/tmp/hospital-filtered.csv']);

        $crawler = $this->requestAsUser($client, $owner, \sprintf('/import?hospitalId=%d', $hospital->getId()));

        self::assertStringContainsString('Active filters', $crawler->text());
        self::assertStringContainsString('St. Test Hospital', $crawler->text());
    }

    public function testParticipantDoesNotSeeForeignHospitalImports(): void
    {
        $client = self::createClient();
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();
        $other = UserFactory::createOne([
            'username' => 'other-'.bin2hex(random_bytes(3)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $foreignHospital = HospitalFactory::createOne([
            'name' => 'Foreign Hospital',
            'owner' => $other,
            'createdBy' => $createdBy,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        $this->createImportForList('Visible Import', $hospital, $createdBy, ['filePath' => '/tmp/visible.csv']);
        $this->createImportForList('Secret Foreign Import', $foreignHospital, $createdBy, ['filePath' => '/tmp/secret.csv']);

        $crawler = $this->requestAsUser($client, $owner, '/import');

        self::assertStringContainsString('Visible Import', $crawler->text());
        self::assertStringNotContainsString('Secret Foreign Import', $crawler->text());
    }

    public function testAdminSeesImportsFromAllOwners(): void
    {
        $client = self::createClient();
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();
        $other = UserFactory::createOne([
            'username' => 'other-'.bin2hex(random_bytes(3)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
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

        $crawler = $this->requestAsUser($client, $admin, '/import');

        self::assertStringContainsString('Own Import', $crawler->text());
        self::assertStringContainsString('Foreign Import', $crawler->text());
    }

    public function testStatusFilterShowsOnlyMatchingImports(): void
    {
        $client = self::createClient();
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('Completed Import', $hospital, $createdBy, [
            'status' => ImportStatus::COMPLETED,
            'filePath' => '/tmp/completed.csv',
        ]);
        $this->createImportForList('Pending Import', $hospital, $createdBy, ['filePath' => '/tmp/pending.csv']);

        $crawler = $this->requestAsUser($client, $owner, '/import?status='.urlencode(ImportStatus::COMPLETED->value));

        self::assertStringContainsString('Completed Import', $crawler->text());
        self::assertStringNotContainsString('Pending Import', $crawler->text());
    }

    public function testDateRangeFilterWorks(): void
    {
        $client = self::createClient();
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('In Range Import', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-04-01 10:00:00'),
            'filePath' => '/tmp/in-range.csv',
        ]);
        $this->createImportForList('Out Of Range Import', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-05-01 10:00:00'),
            'filePath' => '/tmp/out-of-range.csv',
        ]);

        $crawler = $this->requestAsUser($client, $owner, '/import?createdFrom=2025-04-01&createdUntil=2025-04-30');

        self::assertStringContainsString('In Range Import', $crawler->text());
        self::assertStringNotContainsString('Out Of Range Import', $crawler->text());
    }

    public function testSortByCreatedAtDesc(): void
    {
        $client = self::createClient();
        [$owner, $hospital, $createdBy] = $this->seedBaseActors();

        $this->createImportForList('Older Import', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-01-01 10:00:00'),
            'filePath' => '/tmp/older.csv',
        ]);
        $this->createImportForList('Newer Import', $hospital, $createdBy, [
            'createdAt' => new \DateTimeImmutable('2025-06-01 10:00:00'),
            'filePath' => '/tmp/newer.csv',
        ]);

        $crawler = $this->requestAsUser($client, $owner, '/import?sortBy=createdAt&orderBy=desc');

        $firstName = \trim($crawler->filter('table.table tbody tr')->eq(0)->filter('td')->eq(1)->text(''));
        self::assertSame('Newer Import', $firstName);
    }

    /**
     * @param \Zenstruck\Foundry\Persistence\Proxy<User>|User $user
     */
    private function requestAsUser(KernelBrowser $client, object $user, string $uri): Crawler
    {
        $client->loginUser($user->_real());
        $client->request(Request::METHOD_GET, $uri);
        self::assertResponseIsSuccessful();

        return $client->getCrawler();
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
        $owner = UserFactory::createOne([
            'username' => 'owner-'.\bin2hex(random_bytes(3)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
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
