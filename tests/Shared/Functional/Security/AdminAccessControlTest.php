<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AdminAccessControlTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testAdminRedirectsGuestsToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testAdminIsForbiddenForUserWithoutAdminRole(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminIsAccessibleForAdminUser(): void
    {
        $client = self::createClient();
        $client->followRedirects();
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']])->_real();
        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
    }
}
