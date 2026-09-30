<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use App\Shared\Infrastructure\Repository\DataTablePreferenceRepository;
use App\Statistics\ClosureAnalytics\UI\Twig\ClosureEventTableColumns;
use App\User\Domain\Factory\UserFactory;
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

        $repository = $client->getContainer()->get(DataTablePreferenceRepository::class);
        $preference = $repository->findForUserAndTable($user, $key);
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
        $afterColumnReset = $repository->findForUserAndTable($user, $key);
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
        self::assertNull($repository->findForUserAndTable($user, $key));
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
}
