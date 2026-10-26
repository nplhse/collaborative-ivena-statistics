<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\UI\Twig\HospitalListColumns;
use App\Shared\Domain\Entity\DataTablePreference;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ListHospitalsControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testTableWithResultsIsShown(): void
    {
        // Arrange
        $client = $this->createClientAsAreaUser();
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createMany(5);
        HospitalFactory::createMany(10);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Listing Hospitals');
        self::assertSelectorTextContains('h2', 'Listing Hospitals');

        // Check for the table structure
        self::assertSelectorExists('table.table tbody');
        self::assertSelectorTextContains('table.table thead th:nth-child(2)', 'Name');
        self::assertSelectorTextContains('table.table thead th:nth-child(3)', 'Dispatch Area');

        // Check for table contents
        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 1-10 of 10 results.');
        self::assertSelectorTextContains('#page-result-count', 'Showing 1-10 of 10 results.');
        self::assertSelectorExists('#page-result-count[data-controller="result-count-mirror"]');

        $nameRowText = $rows->eq(0)->filter('td')->eq(1)->text();
        self::assertNotEmpty($nameRowText);

        $stateRow = $rows->eq(0)->filter('td')->eq(3)->text();
        self::assertSame('Hessen', trim($stateRow));

        $userRow = $rows->eq(0)->filter('td')->eq(7)->text();
        self::assertStringContainsString('area-user', trim($userRow));
    }

    public function testTableCanBeSorted(): void
    {
        // Arrange
        $client = $this->createClientAsParticipant();
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['name' => 'ACME Hospital']);
        HospitalFactory::createOne(['name' => 'XYZ Hospital']);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital?sortBy=name&orderBy=desc');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(2, $rows, 'We should see 2 rows of results.');
        $nameRow = $rows->eq(0)->filter('td')->eq(1)->text();
        self::assertSame('XYZ Hospital', trim($nameRow));
    }

    public function testTableCanBePaginated(): void
    {
        // Arrange
        $client = $this->createClientAsParticipant();
        UserFactory::createOne();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(35);

        // Act
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital?page=2');

        // Assert
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('table.table tbody tr');
        self::assertCount(10, $rows, 'We should see 10 rows of results.');
        self::assertSelectorTextContains('#result-count', 'Showing 26-35 of 35 results.');
        self::assertSelectorTextContains('#page-result-count', 'Showing 26-35 of 35 results.');
    }

    public function testParticipatingFilterBadgeUsesTranslatedValue(): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['name' => 'Participating Clinic', 'isParticipating' => true]);
        HospitalFactory::createOne(['name' => 'Catalog Clinic', 'isParticipating' => false]);

        $client->request(Request::METHOD_GET, '/explore/hospital?participating=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.badge.bg-blue-lt', 'Participation: Is participating');
        self::assertSelectorTextContains('table.table tbody', 'Participating Clinic');
        self::assertSelectorTextNotContains('table.table tbody', 'Catalog Clinic');
    }

    public function testParticipatingFilterBadgeIsTranslatedForGermanLocale(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'username' => 'hospital-filter-de',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'locale' => 'de',
        ]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['name' => 'Teilnehmende Klinik', 'isParticipating' => true]);
        HospitalFactory::createOne(['name' => 'Katalogklinik', 'isParticipating' => false]);

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/explore/hospital?participating=1');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.badge.bg-blue-lt', 'Teilnahme: Nimmt teil');
        self::assertSelectorTextNotContains('.badge.bg-blue-lt', 'Yes');
    }

    public function testHospitalAttributeFilterBadgesAreTranslatedForGermanLocale(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'username' => 'hospital-attribute-filter-de',
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
            'locale' => 'de',
        ]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne([
            'name' => 'Großstadt Klinik',
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'size' => HospitalSize::LARGE,
        ]);

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, sprintf(
            '/explore/hospital?tier=%s&location=%s&size=%s',
            HospitalTier::FULL->value,
            HospitalLocation::URBAN->value,
            HospitalSize::LARGE->value,
        ));

        self::assertResponseIsSuccessful();
        $filterAlert = $crawler->filter('.alert.alert-info')->text();
        self::assertStringContainsString('Versorgungsstufe: Umfassende Versorgung', $filterAlert);
        self::assertStringContainsString('Standort: Städtischer Standort', $filterAlert);
        self::assertStringContainsString('Größe: Groß', $filterAlert);
    }

    public function testFilterDrawerRendersTriggerAndFooter(): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne();

        $client->request(Request::METHOD_GET, '/explore/hospital?search=klinik&sortBy=name&orderBy=desc&page=2');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="hospital-filters-drawer-trigger"]');
        self::assertSelectorExists('[data-testid="hospital-filters-drawer"]');
        self::assertSelectorExists('#hospital-filters .offcanvas-footer');
        self::assertSelectorExists('[data-testid="hospital-filters-cancel"]');
        self::assertSelectorExists('[data-testid="hospital-filters-apply"]');
        self::assertSelectorExists('[data-testid="hospital-filters-reset"]');
        self::assertSelectorExists('#hospital-filter-form input[type="hidden"][name="search"][value="klinik"]');
        self::assertSelectorExists('#hospital-filter-form input[type="hidden"][name="sortBy"][value="name"]');
        self::assertSelectorExists('#hospital-filter-form input[type="hidden"][name="orderBy"][value="desc"]');
        self::assertSelectorNotExists('#hospital-filter-form input[type="hidden"][name="page"]');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sortKeys(): iterable
    {
        foreach (HospitalListColumns::SORT_KEYS as $sortKey) {
            yield $sortKey => [$sortKey];
        }
    }

    #[DataProvider('sortKeys')]
    public function testEverySortKeyIsAcceptedInBothDirections(string $sortKey): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(3);

        foreach (['asc', 'desc'] as $orderBy) {
            $crawler = $client->request(Request::METHOD_GET, '/explore/hospital?sortBy='.$sortKey.'&orderBy='.$orderBy);

            self::assertResponseIsSuccessful();
            self::assertCount(3, $crawler->filter('table.table tbody tr'));
        }
    }

    public function testTableCanBeSortedByBeds(): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['name' => 'Small Clinic', 'beds' => 120]);
        HospitalFactory::createOne(['name' => 'Large Clinic', 'beds' => 1100]);
        HospitalFactory::createOne(['name' => 'Medium Clinic', 'beds' => 450]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital?sortBy=beds&orderBy=desc');

        self::assertResponseIsSuccessful();
        self::assertSame(['Large Clinic', 'Medium Clinic', 'Small Clinic'], $this->names($crawler));
    }

    public function testPagesStayDisjointWhenManyRowsShareTheSortValue(): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        // Unique names: the faker draws hospital names from a small pool, and a repeated name would look like
        // a hospital shown on both pages.
        $names = array_map(static fn (int $number): string => \sprintf('Paging Hospital %02d', $number), range(1, 30));
        HospitalFactory::createSequence(array_map(static fn (string $name): array => ['name' => $name, 'tier' => HospitalTier::FULL], $names));

        $first = $this->names($client->request(Request::METHOD_GET, '/explore/hospital?sortBy=tier&limit=25&page=1'));
        $second = $this->names($client->request(Request::METHOD_GET, '/explore/hospital?sortBy=tier&limit=25&page=2'));

        self::assertCount(25, $first);
        self::assertCount(5, $second);
        self::assertSame([], array_intersect($first, $second));
        self::assertEqualsCanonicalizing($names, [...$first, ...$second], 'every hospital appears on exactly one page');
    }

    public function testDefaultViewHidesOptionalColumnsAndOffersThemInTheColumnPicker(): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne();

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital');

        self::assertResponseIsSuccessful();
        $headers = $crawler->filter('table.table thead th')->each(static fn (Crawler $th): string => trim($th->text()));
        self::assertContains('Dispatch Area', $headers);
        self::assertNotContains('Beds', $headers);
        self::assertNotContains('City', $headers);
        self::assertSelectorExists('[data-testid="data-table-columns"]');
        self::assertSelectorExists('form[action="/account/data-table-preferences"] input[name="tableKey"][value="'.HospitalListColumns::PREFERENCE_KEY.'"]');
        self::assertSelectorExists('form[action="/account/data-table-preferences"] input[name="visibleColumns[]"][value="beds"]:not([checked])');
        self::assertSelectorExists('form[action="/account/data-table-preferences"] input[name="visibleColumns[]"][value="name"][disabled]');
    }

    public function testColumnsFromTheUrlOverrideTheDefaultView(): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['name' => 'City Clinic', 'beds' => 321]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital?columns=name,beds,city');

        self::assertResponseIsSuccessful();
        $headers = array_values(array_filter(
            $crawler->filter('table.table thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        ));
        self::assertSame(['Name', 'Beds', 'City'], $headers);
        self::assertStringContainsString('321', $crawler->filter('table.table tbody tr')->text());
    }

    public function testSavedColumnsAndPageSizeAreApplied(): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createMany(30);

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital');
        $token = (string) $crawler->filter('form[action="/account/data-table-preferences"] input[name="_token"]')->attr('value');

        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => $token,
            'tableKey' => HospitalListColumns::PREFERENCE_KEY,
            'returnUrl' => '/explore/hospital?tier=full&columns=name',
            'action' => 'save',
            'visibleColumns' => ['name', 'beds', 'tier'],
            'columnOrder' => ['beds', 'name', 'dispatchArea', 'state', 'location', 'tier', 'size', 'postalCode', 'city', 'participatingSince', 'lastChange'],
            'pageSize' => '50',
        ]);

        self::assertResponseRedirects('/explore/hospital?tier=full');
        $preference = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(DataTablePreference::class)
            ->findOneBy(['tableKey' => HospitalListColumns::PREFERENCE_KEY]);
        self::assertInstanceOf(DataTablePreference::class, $preference);

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital?search=a&tier=');

        self::assertResponseIsSuccessful();
        $headers = $crawler->filter('table.table thead th')->each(static fn (Crawler $th): string => trim($th->text()));
        // The participation icon is not part of the picker and stays the first column.
        self::assertSame(['', 'Beds', 'Name', 'Tier'], $headers);

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital');
        self::assertCount(30, $crawler->filter('table.table tbody tr'));
    }

    public function testFilterSearchAndResetKeepTheViewState(): void
    {
        $client = $this->createClientAsParticipant();
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['tier' => HospitalTier::FULL]);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/explore/hospital?tier=Full&search=klinik&sortBy=beds&orderBy=desc&limit=50&columns=name,beds&columnOrder=beds,name&page=2',
        );

        self::assertResponseIsSuccessful();

        // The filter drawer keeps search and view state; its own selects carry the filters.
        foreach (['search' => 'klinik', 'sortBy' => 'beds', 'orderBy' => 'desc', 'limit' => '50', 'columns' => 'name,beds', 'columnOrder' => 'beds,name'] as $key => $value) {
            self::assertSelectorExists(sprintf('#hospital-filter-form input[type="hidden"][name="%s"][value="%s"][data-query-state-field]', $key, $value));
        }
        self::assertSelectorNotExists('#hospital-filter-form input[type="hidden"][name="tier"]');
        self::assertSelectorNotExists('#hospital-filter-form input[type="hidden"][name="page"]');
        self::assertSelectorExists('#hospital-filter-form[data-controller~="query-state"]');

        // The search form keeps filters and view state, but not the search itself or the page.
        $searchForm = $crawler->filter('form[data-turbo-frame="hospital-list"]');
        self::assertSame('query-state', explode(' ', (string) $searchForm->attr('data-controller'))[1] ?? null);
        $hidden = $searchForm->filter('input[type="hidden"]')->each(
            static fn (Crawler $input): array => [(string) $input->attr('name') => (string) $input->attr('value')],
        );
        self::assertSame([
            'tier' => 'Full',
            'sortBy' => 'beds',
            'orderBy' => 'desc',
            'limit' => '50',
            'columns' => 'name,beds',
            'columnOrder' => 'beds,name',
        ], array_merge(...$hidden));

        // Resetting filters keeps the view, but drops filters and search.
        foreach (['hospital-filters-reset', 'hospital-filters-clear'] as $testId) {
            $href = (string) $crawler->filter(sprintf('[data-testid="%s"]', $testId))->attr('href');
            parse_str((string) parse_url($href, \PHP_URL_QUERY), $query);
            self::assertSame([
                'sortBy' => 'beds',
                'orderBy' => 'desc',
                'limit' => '50',
                'columns' => 'name,beds',
                'columnOrder' => 'beds,name',
            ], $query, $testId);
        }
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('table.table tbody tr')->each(
            static fn (Crawler $row): string => trim($row->filter('td')->eq(1)->text()),
        );
    }
}
