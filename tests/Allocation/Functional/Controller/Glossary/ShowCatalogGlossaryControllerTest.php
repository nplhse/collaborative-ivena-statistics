<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Glossary;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowCatalogGlossaryControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testGlossaryIndexListsEntriesAndRelatedCatalogs(): void
    {
        $client = $this->createClientAsAreaUser();
        $client->request(Request::METHOD_GET, '/explore/glossary');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="glossary-index"]');
        self::assertSelectorExists('[data-testid="glossary-entry"]');
        self::assertSelectorNotExists('[data-testid="glossary-related-infection"]');
        self::assertSelectorNotExists('[data-testid="glossary-related-secondary-transport"]');
    }

    public function testUrgencyGlossaryShowsTermsWithAllocationFilters(): void
    {
        $client = $this->createClientAsAreaUser();
        $crawler = $client->request(Request::METHOD_GET, '/explore/glossary/urgency');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="glossary-detail"]');
        self::assertSelectorTextContains('[data-testid="glossary-title"]', 'Urgency');

        $href = $crawler->filter('[data-testid="glossary-term-filter"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('urgency=1', $href);
    }

    public function testUnknownGlossarySlugReturnsNotFound(): void
    {
        $client = $this->createClientAsAreaUser();
        $client->request(Request::METHOD_GET, '/explore/glossary/unknown');

        self::assertResponseStatusCodeSame(404);
    }
}
