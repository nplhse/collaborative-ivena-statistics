<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Security;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ExploreListRateLimitTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;

    public function testExploreAllocationListIsRateLimited(): void
    {
        $client = static::createClient();

        UserFactory::new(['username' => 'explore-rate-limit', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']])->create();
        $this->acceptEssentialCookiesOnly($client);

        $crawler = $client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            'login[username]' => 'explore-rate-limit',
            'login[password]' => 'password',
        ]);
        $client->submit($form);

        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }

        $client->getContainer()->get('cache.rate_limiter')->clear();

        for ($i = 0; $i < 3; ++$i) {
            $client->request(Request::METHOD_GET, '/explore/allocation');
            self::assertResponseIsSuccessful();
        }

        $client->request(Request::METHOD_GET, '/explore/allocation');
        self::assertResponseStatusCodeSame(429);
    }
}
