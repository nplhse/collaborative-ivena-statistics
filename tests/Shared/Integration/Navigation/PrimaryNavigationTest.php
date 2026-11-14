<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Navigation;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationBuilder;
use App\Shared\Application\Navigation\Menu\NavigationMenu;
use App\Tests\Shared\Support\AssertsUniqueNavigationRoutes;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The primary menu built from the real providers of all contexts (ADR 035, step 3).
 */
#[ResetDatabase]
final class PrimaryNavigationTest extends KernelTestCase
{
    use AssertsUniqueNavigationRoutes;
    use Factories;

    public function testRoutesAndAreaPrefixesOfAllMenusAreUnique(): void
    {
        self::bootKernel();

        self::assertUniqueNavigationRoutes($this->builder());
    }

    public function testAGuestSeesOnlyTheBlog(): void
    {
        self::bootKernel();

        self::assertSame(['blog'], $this->entryIds($this->builder()->build(Menu::Primary)));
    }

    public function testAParticipantSeesTheAreasInTheOrderOfTheHeader(): void
    {
        self::bootKernel();
        $this->signIn(['ROLE_USER', 'ROLE_PARTICIPANT']);

        self::assertSame(
            ['explore', 'statistics', 'import', 'tools', 'blog'],
            $this->entryIds($this->builder()->build(Menu::Primary)),
            'Export needs the export voter, which a user without hospital is not granted.',
        );
    }

    public function testEveryPageOfAnAreaMarksItsEntry(): void
    {
        self::bootKernel();
        $this->signIn(['ROLE_USER', 'ROLE_PARTICIPANT']);
        $builder = $this->builder();

        self::assertSame('statistics', $builder->build(Menu::Primary, 'app_stats_top_lists_show')->activeId());
        self::assertSame('statistics', $builder->build(Menu::Primary, 'app_stats_dashboard')->activeId());
        self::assertSame('explore', $builder->build(Menu::Primary, 'app_explore_hospital_show')->activeId());
        self::assertSame('import', $builder->build(Menu::Primary, 'app_import_index')->activeId());
        self::assertSame('blog', $builder->build(Menu::Primary, 'app_blog_index')->activeId());
        self::assertNull($builder->build(Menu::Primary, 'app_default')->activeId());
    }

    private function builder(): NavigationBuilder
    {
        return self::getContainer()->get(NavigationBuilder::class);
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(array $roles): void
    {
        $user = UserFactory::createOne(['username' => 'primary-nav-user', 'roles' => $roles]);
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /**
     * @return list<string>
     */
    private function entryIds(NavigationMenu $menu): array
    {
        $ids = [];
        foreach ($menu->sections as $section) {
            foreach ($section->nodes as $node) {
                $ids[] = $node->item->id;
            }
        }

        return array_values(array_filter($ids, static fn (string $id): bool => !str_starts_with($id, 'page-')));
    }
}
