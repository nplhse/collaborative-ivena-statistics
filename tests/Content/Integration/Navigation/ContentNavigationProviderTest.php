<?php

declare(strict_types=1);

namespace App\Tests\Content\Integration\Navigation;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Content\UI\Navigation\ContentNavigationProvider;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavItem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ContentNavigationProviderTest extends KernelTestCase
{
    use Factories;

    public function testThePrimaryMenuHasTheBlogAndOnlyAvailableHeaderPages(): void
    {
        self::bootKernel();
        PageFactory::createOne([
            'slug' => 'about-nav',
            'path' => '/about-nav',
            'key' => PageKey::About,
            'status' => PageTranslation::STATUS_PUBLISHED,
            'visibility' => Page::VISIBILITY_PUBLIC,
            'title' => 'About Nav',
        ]);

        $items = self::getContainer()->get(ContentNavigationProvider::class)->items(Menu::Primary);
        $translator = self::getContainer()->get(TranslatorInterface::class);

        self::assertSame(['blog', 'page-about'], array_map(static fn (NavItem $item): string => $item->id, $items));
        self::assertSame('About Nav', $items[1]->label->trans($translator));
        self::assertSame('/about-nav', $items[1]->url);
        self::assertNull($items[1]->route, 'A page entry links by URL and never becomes active.');
    }

    public function testOtherMenusGetNothing(): void
    {
        self::bootKernel();

        self::assertSame([], self::getContainer()->get(ContentNavigationProvider::class)->items(Menu::Statistics));
    }
}
