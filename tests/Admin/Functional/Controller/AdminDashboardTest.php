<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AdminDashboardTest extends WebTestCase
{
    use Factories;

    public function testDashboardShowsTilesWithoutRedirectingToUserCrud(): void
    {
        $client = self::createClient();

        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'dashboard-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        $text = $client->getCrawler()->text();
        self::assertStringContainsString('Users', $text);
        self::assertStringContainsString('Pages', $text);
        self::assertStringContainsString('Feedback', $text);
        self::assertStringContainsString('Reference data and allocations', $text);
        self::assertStringContainsString('Key metrics (last 30 days)', $text);
        self::assertStringContainsString('Recent failed imports', $text);
        self::assertStringContainsString('Daily trend (last 30 days)', $text);

        $path = $client->getRequest()->getPathInfo();
        self::assertMatchesRegularExpression('#^/admin/?$#', $path);
    }
}
