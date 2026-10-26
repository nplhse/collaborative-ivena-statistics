<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Controller;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\UI\Twig\ImportListColumns;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListImportDataTableTest extends WebTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{string}>
     */
    public static function sortKeys(): iterable
    {
        foreach (ImportListColumns::SORT_KEYS as $sortKey) {
            yield $sortKey => [$sortKey];
        }
    }

    #[DataProvider('sortKeys')]
    public function testEverySortKeyIsAcceptedInBothDirections(string $sortKey): void
    {
        $client = self::createClient();
        [$owner, $hospital] = $this->actors();
        $this->import('First', $hospital, $owner);
        $this->import('Second', $hospital, $owner);
        $client->loginUser($owner);

        foreach (['asc', 'desc'] as $orderBy) {
            $crawler = $client->request(Request::METHOD_GET, '/import?sortBy='.$sortKey.'&orderBy='.$orderBy);

            self::assertResponseIsSuccessful();
            self::assertCount(2, $crawler->filter('table.table tbody tr'));
        }
    }

    public function testTableCanBeSortedByRejectedRows(): void
    {
        $client = self::createClient();
        [$owner, $hospital] = $this->actors();
        $this->import('Clean', $hospital, $owner, ['rowsRejected' => 0]);
        $this->import('Messy', $hospital, $owner, ['rowsRejected' => 40]);
        $this->import('Some', $hospital, $owner, ['rowsRejected' => 3]);
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import?sortBy=rowsRejected&orderBy=desc&columns=name,rowsRejected');

        self::assertResponseIsSuccessful();
        self::assertSame([['Messy', '40'], ['Some', '3'], ['Clean', '0']], $this->cells($crawler));
    }

    public function testOptionalColumnsFormatSizeRunTimeAndExportPeriod(): void
    {
        $client = self::createClient();
        [$owner, $hospital] = $this->actors();
        $this->import('Big file', $hospital, $owner, [
            'fileSize' => 3 * 1048576,
            'runTime' => 2500,
            'rowCount' => 12000,
            'exportStartsAt' => new \DateTimeImmutable('2026-01-01'),
            'exportEndsAt' => new \DateTimeImmutable('2026-03-31'),
        ]);
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import?columns=name,rowCount,fileSize,runTime,exportPeriod');

        self::assertResponseIsSuccessful();
        self::assertSame(['Name', 'Row count', 'File size', 'Run time', 'Export period'], $this->headers($crawler));
        self::assertSame([['Big file', '12.000', '3,00 MB', '2,50 s', '01.01.2026 – 31.03.2026']], $this->cells($crawler));
    }

    public function testBulkSelectionStaysTheFirstColumnWithAPickedView(): void
    {
        $client = self::createClient();
        [$owner, $hospital] = $this->actors();
        $this->import('Mine', $hospital, $owner);
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import?columns=name,rowsPassed&columnOrder=rowsPassed,name');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-table-bulk-select-all"]');
        self::assertSelectorExists(sprintf('input[name="tableKey"][value="%s"]', ImportListColumns::PREFERENCE_KEY));
        $cells = $crawler->filter('table.table tbody tr')->first()->filter('td');
        self::assertCount(3, $cells);
        self::assertCount(1, $cells->eq(0)->filter('input[type="checkbox"]'));
        self::assertSame(['Successful rows', 'Name'], $this->headers($crawler));
    }

    public function testSearchFilterAndResetKeepTheView(): void
    {
        $client = self::createClient();
        [$owner, $hospital] = $this->actors();
        $this->import('Mine', $hospital, $owner);
        $client->loginUser($owner);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/import?status=Pending&search=Mine&sortBy=rowCount&orderBy=asc&limit=50&columns=name,rowCount&page=2',
        );

        self::assertResponseIsSuccessful();

        $searchForm = $crawler->filter('form[data-turbo-frame="import-list"]');
        self::assertStringContainsString('query-state', (string) $searchForm->attr('data-controller'));
        $hidden = $searchForm->filter('input[type="hidden"]')->each(
            static fn (Crawler $input): array => [(string) $input->attr('name') => (string) $input->attr('value')],
        );
        // Filters and view stay, the page does not (it used to be copied as well).
        self::assertSame(
            ['status' => 'Pending', 'sortBy' => 'rowCount', 'orderBy' => 'asc', 'limit' => '50', 'columns' => 'name,rowCount'],
            array_merge(...$hidden),
        );

        foreach (['sortBy' => 'rowCount', 'limit' => '50', 'columns' => 'name,rowCount', 'search' => 'Mine'] as $key => $value) {
            self::assertSelectorExists(sprintf('#import-filter-form input[type="hidden"][name="%s"][value="%s"]', $key, $value));
        }

        foreach (['import-filters-reset', 'import-filters-clear'] as $testId) {
            $href = (string) $crawler->filter(sprintf('[data-testid="%s"]', $testId))->attr('href');
            parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
            self::assertSame(
                ['sortBy' => 'rowCount', 'orderBy' => 'asc', 'limit' => '50', 'columns' => 'name,rowCount', 'search' => 'Mine'],
                $query,
                $testId,
            );
        }
    }

    public function testSavedColumnsAreApplied(): void
    {
        $client = self::createClient();
        [$owner, $hospital] = $this->actors();
        $this->import('Mine', $hospital, $owner);
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import');
        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => (string) $crawler->filter('form[action="/account/data-table-preferences"] input[name="_token"]')->attr('value'),
            'tableKey' => ImportListColumns::PREFERENCE_KEY,
            'returnUrl' => '/import',
            'action' => 'save',
            'visibleColumns' => ['name', 'status', 'rowsRejected'],
            'columnOrder' => ['type', 'name', 'status', 'hospital', 'rowsRejected', 'rowCount', 'rowsPassed', 'rowsDeduplicated', 'fileSize', 'runTime', 'runCount', 'exportPeriod', 'createdAt', 'lastChange', 'lastChangedBy'],
            'pageSize' => '25',
        ]);
        self::assertResponseRedirects('/import');

        $crawler = $client->request(Request::METHOD_GET, '/import?status=Pending');

        self::assertSame(['Name', 'Status', 'Rejected rows'], $this->headers($crawler));
    }

    /**
     * @return array{0: User, 1: Hospital}
     */
    private function actors(): array
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $owner,
            'state' => StateFactory::createOne(),
            'dispatchArea' => DispatchAreaFactory::createOne(),
        ]);

        return [$owner, $hospital];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function import(string $name, Hospital $hospital, User $createdBy, array $overrides = []): void
    {
        ImportFactory::createOne([
            'name' => $name,
            'hospital' => $hospital,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'createdAt' => new \DateTimeImmutable('now'),
            'fileExtension' => 'csv',
            'fileMimeType' => 'text/csv',
            'fileSize' => 2048,
            'rowCount' => 5,
            'rowsPassed' => 4,
            'rowsRejected' => 1,
            'runCount' => 1,
            'runTime' => 300,
            'createdBy' => $createdBy,
            ...$overrides,
        ]);
    }

    public function testListUsesMobileCardsWithTheNameAsTitle(): void
    {
        $client = self::createClient();
        [$owner, $hospital] = $this->actors();
        $this->import('Mine', $hospital, $owner);
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import?columns=name,status,rowsPassed&columnOrder=rowsPassed,status,name');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-table-cards"].data-table-cards--lg table[role="table"]');
        self::assertSelectorExists('[data-testid="data-table-sort"]');

        $row = $crawler->filter('table.table tbody tr')->first();
        self::assertSame('Mine', trim($row->filter('td.data-table-cell--primary')->text()));
        self::assertCount(1, $row->filter('td.data-table-cell--select input[name="ids[]"]'));
        self::assertSame(
            ['Successful rows', 'Status'],
            $row->filter('.data-table-cell__label')->each(static fn (Crawler $label): string => trim($label->text())),
            'Labels follow the picked columns and their order; the title has none.',
        );
        self::assertSelectorTextContains('thead label.data-table-select-page', 'Select all rows on this page');
    }

    public function testEmptyListShowsTheEmptyStateInsteadOfACard(): void
    {
        $client = self::createClient();
        [$owner] = $this->actors();
        $client->loginUser($owner);

        $crawler = $client->request(Request::METHOD_GET, '/import?search=nothing');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('table.table tbody tr.data-table-row--empty .empty-title'));
        self::assertCount(0, $crawler->filter('table.table tbody .data-table-cell__label'));
    }

    /**
     * Column headers without the bulk checkbox column.
     *
     * @return list<string>
     */
    private function headers(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('table.table thead th:not(.data-table-cell--select)')->each(static fn (Crawler $th): string => trim($th->text())),
        ));
    }

    /**
     * Cell values without the bulk checkbox column and without the mobile card labels.
     *
     * @return list<list<string>>
     */
    private function cells(Crawler $crawler): array
    {
        return $crawler->filter('table.table tbody tr')->each(
            static fn (Crawler $row): array => array_values(array_filter(
                $row->filter('td:not(.data-table-cell--select)')->each(static function (Crawler $cell): string {
                    $value = $cell->filter('.data-table-cell__value');

                    return trim(preg_replace('/\s+/', ' ', ($value->count() > 0 ? $value : $cell)->text()) ?? '');
                }),
                static fn (string $text): bool => '' !== $text,
            )),
        );
    }
}
