<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Security;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Smoke: Explore GETs remain reachable under the generous when@test explore_list limit.
 * Enforcement behaviour is covered by ExploreListRateLimitSubscriberTest (unit).
 */
#[ResetDatabase]
final class ExploreListRateLimitTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;

    public function testPreviouslyUnlimitedExploreListRemainsReachableForParticipant(): void
    {
        $client = self::createClient();

        UserFactory::new(['username' => 'explore-rate-limit-smoke', 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']])->create();
        $this->acceptEssentialCookiesOnly($client);

        $crawler = $client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            'login[username]' => 'explore-rate-limit-smoke',
            'login[password]' => 'password',
        ]);
        $client->submit($form);

        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }

        $client->getContainer()->get('cache.rate_limiter')->clear();

        for ($i = 0; $i < 5; ++$i) {
            $client->request(Request::METHOD_GET, '/explore/indication');
            self::assertResponseIsSuccessful();
        }
    }
}
