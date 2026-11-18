<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Without prod, no CSP header is sent. The prod header itself is checked by bin/check-prod-csp in the CI job
 * "Prod kernel smoke", which builds the prod container anyway (#1343).
 */
#[ResetDatabase]
final class ContentSecurityPolicyTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testTestEnvHasNoCspHeader(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/statistics/');

        self::assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testExploreAllocationAndStatisticsLoad(): void
    {
        $client = self::createClient();
        $this->loginAsParticipant($client);

        $client->request(Request::METHOD_GET, '/explore/allocation');
        self::assertResponseIsSuccessful();

        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/statistics/');
        self::assertResponseIsSuccessful();
    }
}
