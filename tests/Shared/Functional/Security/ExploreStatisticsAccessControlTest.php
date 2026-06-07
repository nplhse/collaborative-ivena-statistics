<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ExploreStatisticsAccessControlTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testExploreRedirectsGuestsToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/explore');

        self::assertResponseRedirects('/login');
    }

    public function testStatisticsRedirectsGuestsToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/statistics/');

        self::assertResponseRedirects('/login');
    }

    public function testExploreIsForbiddenForUserWithoutParticipantRole(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/explore');

        self::assertResponseStatusCodeSame(403);
    }

    public function testExploreIsSuccessfulForParticipant(): void
    {
        $client = self::createClient();
        $this->loginAsParticipant($client);
        $client->request(Request::METHOD_GET, '/explore');

        self::assertResponseIsSuccessful();
    }

    public function testExploreRejectsNonGetMethodsForParticipant(): void
    {
        $client = self::createClient();
        $this->loginAsParticipant($client);
        $client->request(Request::METHOD_POST, '/explore');

        self::assertResponseStatusCodeSame(405);
    }

    public function testStatisticsIsSuccessfulForAuthenticatedUser(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/statistics/');

        self::assertResponseIsSuccessful();
    }
}
