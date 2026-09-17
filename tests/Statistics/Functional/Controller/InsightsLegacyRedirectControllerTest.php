<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class InsightsLegacyRedirectControllerTest extends WebTestCase
{
    use Factories;

    public function testLegacyIndexRedirectsToOverviewAndKeepsQuery(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-redirect-index-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-insights', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/insights', $location);
        self::assertStringNotContainsString('indication-insights', $location);
        self::assertStringContainsString('scope=public', $location);
        self::assertStringContainsString('period=all', $location);
    }

    public function testLegacyIndicationDashboardRedirectsToCanonicalShow(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-redirect-show-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication/42', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/insights/indications/42', $location);
        self::assertStringContainsString('scope=public', $location);
    }

    public function testLegacyGroupDashboardRedirectsToCanonicalShow(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-redirect-group-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication-group/7', [
            'scope' => 'public',
            'period' => 'all',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString(
            '/statistics/insights/indication-groups/7',
            (string) $client->getResponse()->headers->get('Location'),
        );
    }

    public function testLegacyCompareRedirectsToCanonicalCompare(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['username' => 'insights-redirect-compare-'.bin2hex(random_bytes(4))]);
        $client->loginUser($user);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/statistics/indication/compare', [
            'scope' => 'public',
            'period' => 'all',
            'indication_a' => '1',
            'indication_b' => '2',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/statistics/insights/compare', $location);
        self::assertStringContainsString('subject_a_dimension=indications', $location);
        self::assertStringContainsString('subject_a_id=1', $location);
        self::assertStringContainsString('subject_b_id=2', $location);
        self::assertStringNotContainsString('indication_a=', $location);
    }
}
