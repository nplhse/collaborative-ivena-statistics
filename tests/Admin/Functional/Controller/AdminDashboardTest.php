<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Browser\Test\HasBrowser;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AdminDashboardTest extends WebTestCase
{
    use Factories;
    use HasBrowser;
    use ResetDatabase;

    public function testDashboardShowsTilesWithoutRedirectingToUserCrud(): void
    {
        $admin = UserFactory::new()
            ->asAdmin()
            ->create([
                'username' => 'dashboard-admin-'.bin2hex(random_bytes(4)),
            ])
        ;

        $browser = $this->browser()
            ->actingAs($admin)
            ->visit('/admin')
            ->assertSuccessful()
            ->assertSee('Users')
            ->assertSee('Pages')
            ->assertSee('Feedback')
            ->assertSee('Reference data and allocations')
        ;

        $path = parse_url($browser->client()->getHistory()->current()->getUri(), \PHP_URL_PATH);
        self::assertMatchesRegularExpression('#^/admin/?$#', (string) $path);
    }
}
