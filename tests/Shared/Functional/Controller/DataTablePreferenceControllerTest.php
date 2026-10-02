<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\Domain\Entity\DataTablePreference;
use App\Shared\Infrastructure\Repository\DataTablePreferenceRepository;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureUnitTableColumns;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DataTablePreferenceControllerTest extends WebTestCase
{
    use Factories;

    public function testAuthenticatedUserCanSaveAndResetTablePreference(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA']]);
        $client->loginUser($user);
        $key = ClosureEventTableColumns::PREFERENCE_KEY;
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=all_time',
        );
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $returnUrl = '/statistics/closure-analytics/details?scope=public&period=year&year=2026&sortBy=actualMinutes&orderBy=asc&closureReasons%5B0%5D=technical_fault&columns=hospital&columnOrder=hospital%2CstartsAt&limit=100&page=2';

        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => $token,
            'tableKey' => $key,
            'returnUrl' => $returnUrl,
            'action' => 'save',
            'visibleColumns' => ['hospital', 'reasons', 'unknown'],
            'columnOrder' => ['reasons', 'hospital', 'unknown', 'startsAt'],
            'pageSize' => '50',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('scope=public', $location);
        self::assertStringContainsString('period=year', $location);
        self::assertStringContainsString('sortBy=actualMinutes', $location);
        self::assertStringContainsString('closureReasons', $location);
        self::assertStringNotContainsString('columns=', $location);
        self::assertStringNotContainsString('columnOrder=', $location);
        self::assertStringNotContainsString('limit=', $location);
        self::assertStringNotContainsString('page=', $location);

        $preference = $this->preferenceFor($client, $user, $key);
        self::assertNotNull($preference);
        $configuration = $preference->getConfiguration();
        self::assertSame(50, $configuration['pageSize']);
        self::assertSame('reasons', $configuration['columnOrder'][0]);
        self::assertNotContains('unknown', $configuration['columnOrder']);
        self::assertContains('startsAt', $configuration['visibleColumns']);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=all_time',
        );
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => $token,
            'tableKey' => $key,
            'returnUrl' => '/statistics/closure-analytics/details?scope=public&period=year&year=2026&sortBy=actualMinutes&orderBy=asc&columns=hospital&limit=100',
            'action' => 'reset-columns',
        ]);
        self::assertResponseRedirects();
        $columnResetLocation = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('sortBy=actualMinutes', $columnResetLocation);
        self::assertStringContainsString('limit=100', $columnResetLocation);
        self::assertStringNotContainsString('columns=', $columnResetLocation);
        $afterColumnReset = $this->preferenceFor($client, $user, $key);
        self::assertNotNull($afterColumnReset);
        $afterConfiguration = $afterColumnReset->getConfiguration();
        self::assertSame(50, $afterConfiguration['pageSize']);
        self::assertSame(
            $client->getContainer()->get(ClosureEventTableColumns::class)->preferenceSchema()->columnOrder,
            $afterConfiguration['columnOrder'],
        );

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=all_time',
        );
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => $token,
            'tableKey' => $key,
            'returnUrl' => '/statistics/closure-analytics/details?scope=public&period=year&year=2026&sortBy=actualMinutes&orderBy=asc&columns=hospital&limit=100',
            'action' => 'reset-sort',
        ]);
        self::assertResponseRedirects();
        $sortResetLocation = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('columns=hospital', $sortResetLocation);
        self::assertStringNotContainsString('sortBy=', $sortResetLocation);
        self::assertStringNotContainsString('orderBy=', $sortResetLocation);
        self::assertStringNotContainsString('limit=', $sortResetLocation);
        $afterSortReset = $this->preferenceFor($client, $user, $key);
        self::assertNotNull($afterSortReset);
        self::assertSame(
            $client->getContainer()->get(ClosureEventTableColumns::class)->preferenceSchema()->defaultPageSize,
            $afterSortReset->getConfiguration()['pageSize'],
        );
        self::assertSame(
            $client->getContainer()->get(ClosureEventTableColumns::class)->preferenceSchema()->columnOrder,
            $afterSortReset->getConfiguration()['columnOrder'],
        );

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=all_time',
        );
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => $token,
            'tableKey' => $key,
            'returnUrl' => '/statistics/closure-analytics/details?scope=public&period=year&year=2026&sortBy=actualMinutes&orderBy=asc&columns=hospital',
            'action' => 'reset',
        ]);
        self::assertResponseRedirects();
        $resetLocation = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('scope=public', $resetLocation);
        self::assertStringContainsString('period=year', $resetLocation);
        self::assertStringNotContainsString('sortBy=', $resetLocation);
        self::assertStringNotContainsString('orderBy=', $resetLocation);
        self::assertStringNotContainsString('columns=', $resetLocation);
        self::assertNull($this->preferenceFor($client, $user, $key));
    }

    public function testUnitTablePreferenceStoresColumnsSortAndPageSize(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA']]);
        $client->loginUser($user);
        $key = ClosureUnitTableColumns::PREFERENCE_KEY;
        $client->request(
            Request::METHOD_GET,
            '/statistics/closure-analytics/details?scope=public&period=all_time',
        );
        $token = $this->csrfToken($client, 'data_table_preference_'.$key);
        $returnUrl = '/statistics/closure-analytics?scope=public&period=all_time&unitsSort=hospital&unitsOrder=asc&unitsColumns=hospital&unitsColumnOrder=hospital%2Cname&unitsLimit=100&unitsPage=2';

        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => $token,
            'tableKey' => $key,
            'returnUrl' => $returnUrl,
            'action' => 'save',
            'visibleColumns' => ['name', 'duration', 'unknown'],
            'columnOrder' => ['duration', 'name', 'unknown'],
            'pageSize' => '50',
            'sortBy' => 'name',
            'orderBy' => 'asc',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('scope=public', $location);
        self::assertStringNotContainsString('unitsColumns=', $location);
        self::assertStringNotContainsString('unitsColumnOrder=', $location);
        self::assertStringNotContainsString('unitsLimit=', $location);
        self::assertStringNotContainsString('unitsPage=', $location);
        self::assertStringNotContainsString('unitsSort=', $location);
        self::assertStringNotContainsString('unitsOrder=', $location);

        $preference = $this->preferenceFor($client, $user, $key);
        self::assertNotNull($preference);
        $configuration = $preference->getConfiguration();
        self::assertSame(50, $configuration['pageSize']);
        self::assertSame('name', $configuration['sortBy']);
        self::assertSame('asc', $configuration['orderBy']);
        self::assertSame('duration', $configuration['columnOrder'][0]);
        self::assertContains('name', $configuration['visibleColumns']);
        self::assertNotContains('unknown', $configuration['visibleColumns']);

        $resolved = $client->getContainer()->get(DataTablePreferenceService::class)->resolve(
            $user,
            $client->getContainer()->get(ClosureUnitTableColumns::class)->preferenceSchema(),
        );
        self::assertSame(['duration', 'name'], array_slice($resolved->visibleOrderedKeys(), 0, 2));
        self::assertSame('name', $resolved->sortBy);
        self::assertSame('asc', $resolved->orderBy);
        self::assertSame(50, $resolved->pageSize);

        $token = $this->csrfToken($client, 'data_table_preference_'.$key);
        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => $token,
            'tableKey' => $key,
            'returnUrl' => '/statistics/closure-analytics?scope=public&period=all_time',
            'action' => 'save',
            'visibleColumns' => ['name', 'hospital'],
            'columnOrder' => ['hospital', 'name'],
            'pageSize' => '25',
        ]);
        $keptSort = $this->preferenceFor($client, $user, $key);
        self::assertNotNull($keptSort);
        self::assertSame('name', $keptSort->getConfiguration()['sortBy']);
        self::assertSame('asc', $keptSort->getConfiguration()['orderBy']);
        self::assertSame(25, $keptSort->getConfiguration()['pageSize']);
    }

    public function testInvalidCsrfAndUnknownTableAreRejected(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER']]));

        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => 'invalid',
            'tableKey' => ClosureEventTableColumns::PREFERENCE_KEY,
        ]);
        self::assertResponseStatusCodeSame(403);

        $client->request(Request::METHOD_POST, '/account/data-table-preferences', [
            '_token' => 'invalid',
            'tableKey' => 'unknown.table',
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $requestStack = $client->getContainer()->get('request_stack');
        $request = $client->getRequest();
        $requestStack->push($request);
        try {
            $token = $client->getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function preferenceFor(KernelBrowser $client, User $user, string $key): ?DataTablePreference
    {
        $entityManager = $client->getContainer()->get('doctrine')->getManager();
        $entityManager->clear();

        return $client->getContainer()->get(DataTablePreferenceRepository::class)
            ->findForUserAndTable($user, $key);
    }
}
