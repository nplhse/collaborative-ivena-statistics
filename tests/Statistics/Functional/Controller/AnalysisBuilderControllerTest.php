<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AnalysisBuilderControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testBuilderShowsScopeDropdown(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/builder?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $labels = $this->scopePrimaryMenuLabels($crawler);
        self::assertContains('Public', $labels);
    }

    public function testInvalidMyHospitalsScopeRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/builder?scope=my_hospitals&period=all',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('scope=public', $location);
        $this->assertStringNotContainsString('my_hospitals', $location);
    }

    public function testBuilderFormPreservesScopeQueryFields(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/builder?scope=public&period=all&year=2025',
        );

        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="stats-analytics-builder-form"] form');
        self::assertGreaterThan(0, $form->count());

        $scopeInput = $form->filter('input[name="scope"]');
        self::assertCount(1, $scopeInput);
        self::assertSame('public', $scopeInput->attr('value'));

        $periodInput = $form->filter('input[name="period"]');
        self::assertCount(1, $periodInput);
        self::assertSame('all', $periodInput->attr('value'));
    }

    /**
     * @return list<string>
     */
    private function scopePrimaryMenuLabels(Crawler $crawler): array
    {
        return $crawler
            ->filter('.page-header .dropdown-menu .dropdown-item')
            ->each(static fn (Crawler $node): string => trim($node->text()));
    }
}
