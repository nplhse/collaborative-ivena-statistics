<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\UI\Twig\IndicationNormalizedListColumns;
use App\Allocation\UI\Twig\IndicationRawListColumns;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListIndicationsDataTableTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sortKeys(): iterable
    {
        foreach (IndicationRawListColumns::SORT_KEYS as $sortKey) {
            yield 'raw '.$sortKey => ['raw', $sortKey];
        }
        foreach (IndicationNormalizedListColumns::SORT_KEYS as $sortKey) {
            yield 'normalized '.$sortKey => ['normalized', $sortKey];
        }
    }

    #[DataProvider('sortKeys')]
    public function testEverySortKeyIsAcceptedInBothDirections(string $type, string $sortKey): void
    {
        $client = $this->createClientAsParticipant();
        IndicationNormalizedFactory::createMany(2);
        IndicationRawFactory::createMany(2);

        foreach (['asc', 'desc'] as $orderBy) {
            $crawler = $client->request(Request::METHOD_GET, sprintf('/explore/indication?type=%s&sortBy=%s&orderBy=%s', $type, $sortKey, $orderBy));

            self::assertResponseIsSuccessful();
            self::assertCount(2, $crawler->filter('table.table tbody tr'));
        }
    }

    public function testEachTabUsesItsOwnCardBreakpoint(): void
    {
        $client = $this->createClientAsParticipant();
        $this->allocationBasics();
        IndicationRawFactory::createOne(['code' => 7, 'name' => 'Chest pain']);

        $client->request(Request::METHOD_GET, '/explore/indication?type=raw');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-table-cards"].data-table-cards--lg');
        self::assertSelectorTextContains('table.table tbody td.data-table-cell--primary', 'Chest pain');

        $client->request(Request::METHOD_GET, '/explore/indication?type=normalized');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-table-cards"].data-table-cards--md');
    }

    public function testRawOccurrenceColumnShowsPrimaryPlusSecondaryCount(): void
    {
        $client = $this->createClientAsParticipant();
        $this->allocationBasics();
        $raw = IndicationRawFactory::createOne(['code' => 7, 'name' => 'Chest pain']);
        $other = IndicationRawFactory::createOne(['code' => 8, 'name' => 'Other']);
        AllocationFactory::createMany(2, ['indicationRaw' => $raw]);
        AllocationFactory::createOne(['indicationRaw' => $other, 'secondaryIndicationRaw' => $raw]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication?type=raw&columns=name,occurrence&sortBy=occurrence&orderBy=desc');

        self::assertResponseIsSuccessful();
        self::assertSame(['Name', 'Occurrences'], $this->headers($crawler));
        self::assertSame([['Chest pain', '3'], ['Other', '1']], $this->cells($crawler));
    }

    public function testNormalizedOptionalColumnsShowGroupsRawCountAndOccurrence(): void
    {
        $client = $this->createClientAsParticipant();
        $this->allocationBasics();
        $indication = IndicationNormalizedFactory::createOne(['code' => 10, 'name' => 'Stroke', 'note' => 'Check onset time']);
        IndicationRawFactory::createMany(2, ['target' => $indication]);
        IndicationGroupFactory::createOne(['name' => 'Neurology', 'sortOrder' => 1, 'indications' => [$indication]]);
        AllocationFactory::createMany(3, ['indicationNormalized' => $indication]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication?columns=name,note,groups,rawCount,occurrence');

        self::assertResponseIsSuccessful();
        self::assertSame(['Name', 'Note', 'Groups', 'Raw indications', 'Occurrences'], $this->headers($crawler));
        self::assertSame([['Stroke', 'Check onset time', 'Neurology', '2', '3']], $this->cells($crawler));
    }

    public function testEachTabHasItsOwnColumnPicker(): void
    {
        $client = $this->createClientAsParticipant();
        IndicationNormalizedFactory::createOne();
        IndicationRawFactory::createOne();

        $client->request(Request::METHOD_GET, '/explore/indication');
        self::assertSelectorExists(sprintf('input[name="tableKey"][value="%s"]', IndicationNormalizedListColumns::PREFERENCE_KEY));
        self::assertSelectorExists('input[name="visibleColumns[]"][value="groups"]:not([checked])');

        $client->request(Request::METHOD_GET, '/explore/indication?type=raw');
        self::assertSelectorExists(sprintf('input[name="tableKey"][value="%s"]', IndicationRawListColumns::PREFERENCE_KEY));
        self::assertSelectorExists('input[name="visibleColumns[]"][value="occurrence"]:not([checked])');
    }

    public function testSavedRawColumnsDoNotChangeTheNormalizedTab(): void
    {
        $client = $this->createClientAsParticipant();
        IndicationNormalizedFactory::createOne();
        IndicationRawFactory::createOne();

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication?type=raw');
        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => (string) $crawler->filter('input[name="_token"]')->attr('value'),
            'tableKey' => IndicationRawListColumns::PREFERENCE_KEY,
            'returnUrl' => '/explore/indication?type=raw',
            'action' => 'save',
            'visibleColumns' => ['code', 'name'],
            'columnOrder' => ['name', 'code', 'reviewStatus', 'normalized', 'occurrence', 'reviewedAt', 'reviewedBy', 'firstMatchedAt', 'createdAt', 'lastChange', 'lastChangedBy'],
            'pageSize' => '25',
        ]);
        self::assertResponseRedirects('/explore/indication?type=raw');

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication?type=raw');
        self::assertSame(['Name', 'Code'], $this->headers($crawler));

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication');
        self::assertSame(['Code', 'Name', 'Last changed at', 'Last changed by'], $this->headers($crawler));
    }

    public function testSearchFormAndEmptyStateKeepTheViewButNotTheTab(): void
    {
        $client = $this->createClientAsParticipant();
        IndicationRawFactory::createOne(['name' => 'Fever']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication?type=raw&search=nothing&sortBy=name&orderBy=desc&columns=name,code&page=2');

        self::assertResponseIsSuccessful();
        $searchForm = $crawler->filter('form[data-turbo-frame="indication-list"]');
        self::assertStringContainsString('query-state', (string) $searchForm->attr('data-controller'));
        $hidden = $searchForm->filter('input[type="hidden"][data-query-state-field]')->each(
            static fn (Crawler $input): array => [(string) $input->attr('name') => (string) $input->attr('value')],
        );
        self::assertSame(['sortBy' => 'name', 'orderBy' => 'desc', 'columns' => 'name,code'], array_merge(...$hidden));
        // The tab stays a field of its own.
        self::assertCount(1, $searchForm->filter('input[name="type"]'));

        $reset = (string) $crawler->filter('.empty a.btn-primary')->attr('href');
        parse_str((string) parse_url($reset, \PHP_URL_QUERY), $query);
        self::assertSame(['sortBy' => 'name', 'orderBy' => 'desc', 'columns' => 'name,code', 'type' => 'raw'], $query);
    }

    public function testWorklistCanBeSortedByName(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]));
        IndicationRawFactory::createOne(['code' => 1, 'name' => 'Alpha']);
        IndicationRawFactory::createOne(['code' => 2, 'name' => 'Zulu']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication/raw/review?sortBy=name&orderBy=desc');

        self::assertResponseIsSuccessful();
        self::assertSame(['Zulu', 'Alpha'], $crawler->filter('table.table tbody tr')->each(
            static fn (Crawler $row): string => trim($row->filter('td')->eq(1)->filter('.data-table-cell__value')->text()),
        ));
        self::assertSelectorExists('table.table thead th[aria-sort="descending"]');
        self::assertSelectorExists('table.table thead th[aria-sort="none"]');
    }

    private function allocationBasics(): void
    {
        $state = StateFactory::createOne();
        DispatchAreaFactory::createOne(['state' => $state]);
        HospitalFactory::createOne();
        ImportFactory::createOne();
        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
    }

    /**
     * @return list<string>
     */
    private function headers(Crawler $crawler): array
    {
        return array_values(array_filter(
            $crawler->filter('table.table thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        ));
    }

    /**
     * @return list<list<string>>
     */
    private function cells(Crawler $crawler): array
    {
        return $crawler->filter('table.table tbody tr')->each(
            static fn (Crawler $row): array => $row->filter('td')->each(
                static fn (Crawler $cell): string => trim(preg_replace('/\s+/', ' ', ($cell->filter('.data-table-cell__value')->count() > 0 ? $cell->filter('.data-table-cell__value') : $cell)->text()) ?? ''),
            ),
        );
    }
}
