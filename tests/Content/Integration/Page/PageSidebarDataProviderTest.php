<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Page;

use App\Content\Application\Page\PageSidebarDataProvider;
use App\Content\Domain\Entity\Page;
use App\Content\Infrastructure\Factory\PageFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PageSidebarDataProviderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testGetDataExcludesPagesGuestCannotView(): void
    {
        self::bootKernel();

        PageFactory::createOne([
            'slug' => 'sidebar-public',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);

        PageFactory::createOne([
            'slug' => 'sidebar-auth',
            'status' => Page::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_AUTHENTICATED,
        ]);

        $provider = self::getContainer()->get(PageSidebarDataProvider::class);
        $data = $provider->getData();

        $slugs = $this->collectSlugsFromTree($data['pageTree']);

        self::assertContains('sidebar-public', $slugs);
        self::assertNotContains('sidebar-auth', $slugs);
    }

    /**
     * @param array<int, array{page: Page, children: array<int, mixed>}> $nodes
     *
     * @return list<string>
     */
    private function collectSlugsFromTree(array $nodes): array
    {
        $slugs = [];
        foreach ($nodes as $node) {
            $slug = $node['page']->getSlug();
            if (null !== $slug) {
                $slugs[] = $slug;
            }
            $slugs = array_merge($slugs, $this->collectSlugsFromTree($node['children']));
        }

        return $slugs;
    }
}
