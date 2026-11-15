<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * admin-kpi.css comes from the `admin-kpi` asset-mapper entry that DashboardController::configureAssets()
 * adds to every admin page; the templates must not link it a second time (#1236).
 */
#[ResetDatabase]
final class AdminKpiStylesheetTest extends WebTestCase
{
    use Factories;

    /**
     * @return iterable<string, array{string}>
     */
    public static function adminPages(): iterable
    {
        yield 'dashboard' => ['/admin'];
        yield 'messenger' => ['/admin/operations/messenger'];
        yield 'storage' => ['/admin/operations/storage'];
        yield 'failed messages' => ['/admin/operations/failed-messages'];
        yield 'audit timeline' => ['/admin/audit'];
    }

    #[DataProvider('adminPages')]
    public function testAdminKpiStylesheetIsLoadedOnce(string $url): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asAdmin()->create());

        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="admin-kpi"]'));
    }
}
