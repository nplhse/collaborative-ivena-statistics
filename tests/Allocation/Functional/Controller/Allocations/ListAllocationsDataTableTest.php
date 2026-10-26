<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Allocations;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\UI\Twig\AllocationListColumns;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ListAllocationsDataTableTest extends ListAllocationsControllerTestCase
{
    public function testDefaultViewKeepsTheColumnsAndOffersTheOptionalOnes(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        AllocationFactory::createOne();

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation');

        self::assertResponseIsSuccessful();
        self::assertSame(['Timestamps', 'Age', 'Hospital', 'Indication', 'Urgency', 'Properties'], $this->headers($crawler));
        self::assertSelectorExists(sprintf('input[name="tableKey"][value="%s"]', AllocationListColumns::PREFERENCE_KEY));
        self::assertSelectorExists('input[type="checkbox"][name="visibleColumns[]"][value="speciality"]:not([checked])');
        // The row action is fixed and not part of the picker.
        self::assertSelectorNotExists('input[type="checkbox"][name="visibleColumns[]"][value="actions"]');
        self::assertCount(1, $crawler->filter('table.table tbody tr')->first()->filter('td')->last()->filter('.btn-actions a'));
    }

    public function testListUsesMobileCardsWithTheRowActionInTheMenu(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        AllocationFactory::createOne();

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="data-table-cards"].data-table-cards--lg');
        $row = $crawler->filter('table.table tbody tr')->first();
        self::assertCount(1, $row->filter('td.data-table-cell--primary'), 'The timestamps are the card title.');
        $actions = $row->filter('td.data-table-cell--actions');
        self::assertSame(
            $actions->filter('.btn-actions a')->attr('href'),
            $actions->filter('.data-table-actions-menu a.dropdown-item')->attr('href'),
            'The card menu offers the same detail link as the table.',
        );
    }

    public function testOptionalColumnsShowNamesAndKeepTheRowActionLast(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        AllocationFactory::createOne([
            'gender' => AllocationGender::MALE,
            'transportType' => AllocationTransportType::GROUND,
            'occasion' => OccasionFactory::first(),
            'secondaryTransport' => SecondaryTransportFactory::first(),
        ]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?columns=speciality,department,assignment,occasion,secondaryTransport,gender,transportType',
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            // Without columnOrder the columns follow the definition order.
            ['Timestamps', 'Gender', 'Transport Type', 'Speciality', 'Department', 'Assignment', 'Occasion', 'Secondary transport'],
            $this->headers($crawler),
        );
        $cells = $crawler->filter('table.table tbody tr')->first()->filter('td')->each(
            static fn (Crawler $cell): string => trim(preg_replace('/\s+/', ' ', ($cell->filter('.data-table-cell__value')->count() > 0 ? $cell->filter('.data-table-cell__value') : $cell)->text()) ?? ''),
        );
        self::assertSame(
            ['Male', 'Ground', 'Innere Medizin', 'Kardiologie', 'Test Assignment', 'Test Occasion', 'Kapazitätsengpass'],
            \array_slice($cells, 1, 7),
        );
        self::assertCount(1, $crawler->filter('table.table tbody tr')->first()->filter('td')->last()->filter('.btn-actions a'));
    }

    public function testSavedPageSizeAppliesUnlessTheUrlHasALimit(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        AllocationFactory::createMany(60);

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation');
        self::assertCount(50, $crawler->filter('table.table tbody tr'), 'The allocation list keeps its default of 50 rows.');

        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => (string) $crawler->filter('form[action="/account/data-table-preferences"] input[name="_token"]')->attr('value'),
            'tableKey' => AllocationListColumns::PREFERENCE_KEY,
            'returnUrl' => '/explore/allocation',
            'action' => 'save',
            'visibleColumns' => ['timestamps', 'hospital'],
            'columnOrder' => ['timestamps', 'hospital', 'age', 'indication', 'urgency', 'properties', 'gender', 'transportType', 'speciality', 'department', 'assignment', 'occasion', 'secondaryTransport'],
            'pageSize' => '25',
        ]);
        self::assertResponseRedirects('/explore/allocation');

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation');
        self::assertCount(25, $crawler->filter('table.table tbody tr'));
        self::assertSame(['Timestamps', 'Hospital'], $this->headers($crawler));

        $crawler = $client->request(Request::METHOD_GET, '/explore/allocation?limit=10');
        self::assertCount(10, $crawler->filter('table.table tbody tr'));
    }

    public function testFilterAndResetKeepTheViewButDropTheCursor(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedDependencies();
        AllocationFactory::createMany(3);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/allocation?urgency=1&sortBy=age&orderBy=asc&limit=25&columns=hospital,speciality&cursor=abc',
        );

        self::assertResponseIsSuccessful();
        foreach (['sortBy' => 'age', 'orderBy' => 'asc', 'limit' => '25', 'columns' => 'hospital,speciality'] as $key => $value) {
            self::assertSelectorExists(sprintf('#allocation-filter-form input[type="hidden"][name="%s"][value="%s"][data-query-state-field]', $key, $value));
        }
        self::assertSelectorNotExists('#allocation-filter-form input[type="hidden"][name="cursor"]');

        $href = (string) $crawler->filter('[data-testid="allocation-filters-reset"]')->attr('href');
        parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
        self::assertSame(['sortBy' => 'age', 'orderBy' => 'asc', 'limit' => '25', 'columns' => 'hospital,speciality'], $query);
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
}
