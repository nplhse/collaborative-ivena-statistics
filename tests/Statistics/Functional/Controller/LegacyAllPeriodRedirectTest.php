<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * #1017: old `period=all` links (rolling 12 months until v0.3) are redirected to `last_12_months`.
 * Remove together with LegacyAllPeriodRedirectSubscriber in v0.4-beta.
 */
#[ResetDatabase]
final class LegacyAllPeriodRedirectTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testOldPeriodAllLinkRedirectsToLast12Months(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/top-lists?scope=public&period=all');

        $this->assertResponseRedirects('/statistics/top-lists?scope=public&period=last_12_months', 302);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-context-period"] option[value="last_12_months"][selected]');
    }
}
