<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Security;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class CustomAnalysisAccessControlTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testLibraryIsAccessibleForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/analytics/library?scope=public&period=all');

        self::assertResponseIsSuccessful();
    }

    public function testSystemAnalysisViewIsAccessibleForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );

        self::assertResponseIsSuccessful();
    }

    public function testBuilderIsForbiddenForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/analytics/builder?scope=public&period=all');

        self::assertResponseStatusCodeSame(403);
    }

    public function testSavedAnalysisViewIsForbiddenForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/analytics/saved/1?scope=public&period=all');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGenericAnalysisCustomPresetIsForbiddenForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/generic-analysis/custom?scope=public&period=all');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDimensionOverrideOnSystemViewIsForbiddenForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all&ga_primary=hour',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testLibraryHidesBuilderLinkForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all',
        );

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="stats-analytics-builder-header-link"]'));
        self::assertCount(0, $crawler->filter('[data-testid="stats-analytics-builder-entry"]'));
        self::assertCount(0, $crawler->filter('[data-testid="stats-analytics-library-tab-recent"]'));
    }

    public function testSystemAnalysisViewHidesCustomizeButtonForRoleUser(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="stats-analytics-customize-open"]'));
    }

    public function testBuilderIsAccessibleForParticipant(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/statistics/analytics/builder?scope=public&period=all');

        self::assertResponseIsSuccessful();
    }

    public function testLibraryShowsBuilderLinkForParticipant(): void
    {
        $client = $this->createClientAsParticipant();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/library?scope=public&period=all',
        );

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-testid="stats-analytics-builder-header-link"]')->count());
    }
}
