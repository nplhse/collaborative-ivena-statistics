<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Navigation;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Domain\Enum\PageKey;
use App\Content\Infrastructure\Factory\PageFactory;
use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationMenu;
use App\Shared\Application\Navigation\Menu\NavigationNode;
use App\Shared\Application\Navigation\Menu\NavigationSection;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The footer menu built from the real providers (ADR 035, step 4); replaces the tests of the former
 * FooterNavigationProvider.
 */
#[ResetDatabase]
final class FooterNavigationTest extends KernelTestCase
{
    use Factories;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testColumnsWithoutLinksAreOmitted(): void
    {
        $this->createPage(PageKey::About, 'About us', '/about');

        self::assertSame(['project', 'more'], $this->columnIds($this->footer()));
    }

    public function testAllColumnsListPublishedPagesAndStaticLinksInOrder(): void
    {
        $this->createPage(PageKey::About, 'About us', '/about');
        $this->createPage(PageKey::Features, 'Features overview', '/features');
        $this->createPage(PageKey::Faq, 'Frequently asked questions', '/faq');
        $this->createPage(PageKey::Imprint, 'Imprint', '/imprint');
        $this->createPage(PageKey::Privacy, 'Privacy policy', '/privacy');
        $this->createPage(PageKey::Terms, 'Terms of use', '/terms');

        $footer = $this->footer();

        self::assertSame(['project', 'help', 'legal', 'more'], $this->columnIds($footer));
        self::assertSame(
            [
                'project' => ['About us => /about', 'Features overview => /features', 'Blog => /blog'],
                'help' => ['Frequently asked questions => /faq'],
                'legal' => ['Imprint => /imprint', 'Privacy policy => /privacy', 'Terms of use => /terms'],
                'more' => ['Sitemap => /sitemap', 'Cookie settings => /cookies/preferences'],
            ],
            $this->linksByColumn($footer),
        );
    }

    public function testDraftPagesAreSkipped(): void
    {
        $this->createPage(PageKey::About, 'Draft about', '/about-draft', PageTranslation::STATUS_DRAFT);

        $links = $this->linksByColumn($this->footer());

        self::assertSame(['Blog => /blog'], $links['project']);
        self::assertArrayNotHasKey('help', $links);
    }

    public function testASignedInUserSeesActivityAsLastProjectLink(): void
    {
        $this->authenticate(UserFactory::createOne(['roles' => ['ROLE_USER']]));

        self::assertSame(['Blog => /blog', 'Activity => /activity'], $this->linksByColumn($this->footer())['project']);
    }

    public function testAGuestDoesNotSeeActivity(): void
    {
        self::assertSame(['Blog => /blog'], $this->linksByColumn($this->footer())['project']);
    }

    public function testNoFooterLinkIsActive(): void
    {
        self::assertSame([], self::getContainer()->get(NavigationBuilder::class)->build(Menu::Footer)->activePath);
    }

    private function footer(): NavigationMenu
    {
        return self::getContainer()->get(NavigationBuilder::class)->build(Menu::Footer);
    }

    /**
     * @return list<string>
     */
    private function columnIds(NavigationMenu $menu): array
    {
        return array_map(static fn (NavigationSection $section): string => $section->group->id, $menu->sections);
    }

    /**
     * @return array<string, list<string>> column id => "label => url"
     */
    private function linksByColumn(NavigationMenu $menu): array
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        $links = [];
        foreach ($menu->sections as $section) {
            $links[$section->group->id] = array_map(
                static fn (NavigationNode $node): string => $node->item->label->trans($translator, 'en')
                    .' => '.($node->item->url ?? $urlGenerator->generate((string) $node->item->route, $node->item->routeParameters)),
                $section->nodes,
            );
        }

        return $links;
    }

    private function createPage(PageKey $key, string $title, string $path, string $status = PageTranslation::STATUS_PUBLISHED): void
    {
        PageFactory::createOne([
            'title' => $title,
            'slug' => ltrim($path, '/'),
            'path' => $path,
            'key' => $key,
            'status' => $status,
            'visibility' => Page::VISIBILITY_PUBLIC,
        ]);
    }

    private function authenticate(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
