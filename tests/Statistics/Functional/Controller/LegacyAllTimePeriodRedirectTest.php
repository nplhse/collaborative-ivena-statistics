<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #1035: old `period=all_time` links (whole history until v0.3) are redirected to `period=all`.
 * Remove together with LegacyAllTimePeriodRedirectSubscriber in v1.0.
 */
#[ResetDatabase]
final class LegacyAllTimePeriodRedirectTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testOldPeriodAllTimeLinkRedirectsToAll(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/top-lists?scope=public&period=all_time');

        $this->assertResponseRedirects('/statistics/top-lists?scope=public&period=all', 301);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="all"][selected]');
    }
}
