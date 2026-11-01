<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Twig;

use App\Content\Application\Page\PageNavigationTreeProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Twig\Environment;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Tree in every test: Guide (key faq) > [Install > [Docker], Usage]; Members (signed-in only) > [Inside].
 */
#[ResetDatabase]
final class PageNavigationComponentTest extends KernelTestCase
{
    use Factories;
    use InteractsWithTwigComponents;

    /** @var array<string, Page> */
    private array $pages = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $guide = $this->page('Guide', null, 0, PageKey::Faq);
        $install = $this->page('Install', $guide, 0);
        $this->page('Docker', $install, 0);
        $this->page('Usage', $guide, 1);
        $members = $this->page('Members', null, 1, null, Page::VISIBILITY_AUTHENTICATED);
        $this->page('Inside', $members, 0);
    }

    public function testFullTreeRendersNestedListWithSeparateToggles(): void
    {
        $crawler = $this->render([]);

        self::assertSame(['Guide', 'Install', 'Docker', 'Usage'], $crawler->filter('a.page-nav-link')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('Pages', trim($crawler->filter('h3.card-title')->text()));

        // Only Guide and Install have children, so only they get a toggle.
        $toggles = $crawler->filter('button.page-nav-toggle');
        self::assertCount(2, $toggles);
        self::assertSame('Subpages of Guide', $toggles->eq(0)->attr('aria-label'));
        self::assertSame('false', $toggles->eq(0)->attr('aria-expanded'));

        // Without a current page every branch is collapsed.
        self::assertCount(2, $crawler->filter('ul.page-nav-branch[hidden]'));
        self::assertCount(0, $crawler->filter('[aria-current]'));
    }

    public function testCurrentPageIsMarkedAndItsAncestorsAreExpanded(): void
    {
        $this->setCurrentPage('Docker');

        $crawler = $this->render([]);

        $current = $crawler->filter('[aria-current="page"]');
        self::assertCount(1, $current);
        self::assertSame('Docker', $current->text());
        self::assertSame(['Guide', 'Install'], $crawler->filter('li.is-ancestor > .page-nav-row > a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame(['true', 'true'], $crawler->filter('button.page-nav-toggle')->each(static fn (Crawler $b): ?string => $b->attr('aria-expanded')));
        self::assertCount(0, $crawler->filter('ul.page-nav-branch[hidden]'));
    }

    public function testSubtreeByKeyUsesRootTitleAsHeading(): void
    {
        $crawler = $this->render(['root' => PageKey::Faq]);

        self::assertSame('Guide', trim($crawler->filter('h3.card-title')->text()));
        self::assertSame(['Install', 'Docker', 'Usage'], $crawler->filter('a.page-nav-link')->each(static fn (Crawler $a): string => $a->text()));
    }

    public function testSubtreeWithRootAndTitleShowsRootExpanded(): void
    {
        $crawler = $this->render(['root' => $this->pages['Guide'], 'includeRoot' => true, 'title' => 'In this guide']);

        self::assertSame('In this guide', trim($crawler->filter('h3.card-title')->text()));
        self::assertSame('Guide', $crawler->filter('a.page-nav-link')->first()->text());
        self::assertSame('true', $crawler->filter('button.page-nav-toggle')->first()->attr('aria-expanded'));
    }

    public function testCurrentPageOutsideTheSubtreeMarksNothing(): void
    {
        $this->setCurrentPage('Members');

        $crawler = $this->render(['root' => $this->pages['Guide']]);

        self::assertCount(0, $crawler->filter('[aria-current]'));
        self::assertCount(0, $crawler->filter('li.is-ancestor'));
    }

    public function testInaccessibleOrMissingRootRendersNothing(): void
    {
        self::assertSame('', trim((string) $this->renderTwigComponent('Content:PageNavigation', ['root' => $this->pages['Members']])));
        self::assertSame('', trim((string) $this->renderTwigComponent('Content:PageNavigation', ['root' => PageKey::Imprint])));
        self::assertSame('', trim((string) $this->renderTwigComponent('Content:PageNavigation', ['root' => $this->pages['Docker']])));
    }

    public function testTwoInstancesUseDistinctIdsThatTheirControlsPointTo(): void
    {
        $html = self::getContainer()->get(Environment::class)
            ->createTemplate('<twig:Content:PageNavigation /><twig:Content:PageNavigation :root="root" />')
            ->render(['root' => $this->pages['Guide']]);
        $crawler = new Crawler($html);

        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): ?string => $node->attr('id'));
        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertCount(2, $crawler->filter('nav[aria-labelledby]'));

        foreach ($crawler->filter('[aria-controls]')->each(static fn (Crawler $b): ?string => $b->attr('aria-controls')) as $controlled) {
            self::assertCount(1, $crawler->filter('#'.$controlled));
        }
        foreach ($crawler->filter('nav[aria-labelledby]')->each(static fn (Crawler $n): ?string => $n->attr('aria-labelledby')) as $label) {
            self::assertCount(1, $crawler->filter('#'.$label));
        }
    }

    public function testSeveralInstancesLoadThePageTreeOnce(): void
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $debugData);
        $debugData->reset();

        self::getContainer()->get(Environment::class)
            ->createTemplate('<twig:Content:PageNavigation /><twig:Content:PageNavigation :root="root" /><twig:Content:PageNavigation :root="key" />')
            ->render(['root' => $this->pages['Guide'], 'key' => PageKey::Faq]);

        $pageQueries = [];
        foreach ($debugData->getData() as $connectionQueries) {
            foreach ($connectionQueries as $query) {
                $sql = (string) ($query['sql'] ?? '');
                if (1 === preg_match('/\bFROM page\b/i', $sql)) {
                    $pageQueries[] = $sql;
                }
            }
        }

        self::assertCount(1, $pageQueries, implode("\n", $pageQueries));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): Crawler
    {
        return $this->renderTwigComponent('Content:PageNavigation', $props)->crawler();
    }

    private function setCurrentPage(string $title): void
    {
        $request = Request::create('/');
        $request->attributes->set(PageNavigationTreeProvider::CURRENT_PAGE_ATTRIBUTE, $this->pages[$title]->getId());
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    private function page(string $title, ?Page $parent, int $sortOrder, ?PageKey $key = null, string $visibility = Page::VISIBILITY_PUBLIC): Page
    {
        $page = PageFactory::createOne([
            'title' => $title,
            'slug' => 'nav-'.strtolower($title),
            'parent' => $parent,
            'sortOrder' => $sortOrder,
            'key' => $key,
            'visibility' => $visibility,
            'status' => PageTranslation::STATUS_PUBLISHED,
        ]);

        return $this->pages[$title] = $page;
    }
}
