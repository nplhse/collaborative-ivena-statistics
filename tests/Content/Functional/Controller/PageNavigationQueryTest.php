<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Factory\PageFactory;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The page tree is loaded only where a page navigation is rendered, and then once.
 */
#[ResetDatabase]
final class PageNavigationQueryTest extends WebTestCase
{
    use Factories;

    public function testPageViewLoadsTheTreeOnce(): void
    {
        $client = self::createClient();
        $this->createPages();

        self::assertSame(1, $this->treeQueryCount($client, '/nav-query-parent/nav-query-child'));
    }

    public function testPageWithoutNavigationDoesNotLoadTheTree(): void
    {
        $client = self::createClient();
        $this->createPages();

        self::assertSame(0, $this->treeQueryCount($client, '/blog'));
    }

    private function createPages(): void
    {
        $parent = PageFactory::createOne(['title' => 'Parent', 'slug' => 'nav-query-parent', 'status' => PageTranslation::STATUS_PUBLISHED]);
        PageFactory::createOne([
            'title' => 'Child',
            'slug' => 'nav-query-child',
            'parent' => $parent,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'content' => [['type' => 'richtext', 'data' => ['html' => '<p>Child</p>']]],
        ]);
    }

    /**
     * Counts the tree query (pages joined with their parent); the header and footer key lookup does not join the parent.
     */
    private function treeQueryCount(KernelBrowser $client, string $uri): int
    {
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, $uri);
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertNotFalse($profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $count = 0;
        foreach ($collector->getQueries() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                if (1 === preg_match('/\bFROM page \w+ LEFT JOIN page\b/i', (string) ($query['sql'] ?? ''))) {
                    ++$count;
                }
            }
        }

        return $count;
    }
}
