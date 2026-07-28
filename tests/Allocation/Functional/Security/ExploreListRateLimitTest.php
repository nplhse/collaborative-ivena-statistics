<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Security;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
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
        $client = $this->createLoggedInParticipantClient('explore-rate-limit');
        $client->getContainer()->get('cache.rate_limiter')->clear();

        for ($i = 0; $i < 3; ++$i) {
            $client->request(Request::METHOD_GET, '/explore/allocation');
            self::assertResponseIsSuccessful();
        }

        $client->request(Request::METHOD_GET, '/explore/allocation');
        self::assertResponseStatusCodeSame(429);
    }

    public function testPreviouslyUnlimitedExploreListsShareTheSameBucket(): void
    {
        $client = $this->createLoggedInParticipantClient('explore-rate-limit-mixed');
        $client->getContainer()->get('cache.rate_limiter')->clear();

        $client->request(Request::METHOD_GET, '/explore/allocation');
        self::assertResponseIsSuccessful();

        $client->request(Request::METHOD_GET, '/explore/indication');
        self::assertResponseIsSuccessful();

        $client->request(Request::METHOD_GET, '/explore/infection');
        self::assertResponseIsSuccessful();

        $client->request(Request::METHOD_GET, '/explore/speciality');
        self::assertResponseStatusCodeSame(429);
    }

    public function testNonExplorePathsAreNotAffectedByExploreListLimiter(): void
    {
        $client = $this->createLoggedInParticipantClient('explore-rate-limit-other');
        $client->getContainer()->get('cache.rate_limiter')->clear();

        for ($i = 0; $i < 3; ++$i) {
            $client->request(Request::METHOD_GET, '/explore/allocation');
            self::assertResponseIsSuccessful();
        }

        $client->request(Request::METHOD_GET, '/statistics/');
        self::assertResponseIsSuccessful();
    }

    private function createLoggedInParticipantClient(string $username): KernelBrowser
    {
        $client = self::createClient();

        UserFactory::new(['username' => $username, 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']])->create();
        $this->acceptEssentialCookiesOnly($client);

        $crawler = $client->request(Request::METHOD_GET, '/login');
        $form = $crawler->selectButton('Sign in')->form([
            'login[username]' => $username,
            'login[password]' => 'password',
        ]);
        $client->submit($form);

        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }

        return $client;
    }
}
