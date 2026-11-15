<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\Navigation\Menu;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavGroup;
use App\Shared\Application\Navigation\Menu\NavigationContributions;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavigationVisibility;
use App\Shared\Application\Navigation\Menu\NavItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Merging the providers' contributions and the visibility of every entry (ADR 035, #1237).
 */
final class NavigationContributionsTest extends TestCase
{
    public function testADuplicateIdKeepsTheFirstRegistrationAndIsReported(): void
    {
        $first = $this->provider([new NavGroup('capacity')], [$this->item('closures', 'capacity', 'r_first')]);
        $second = $this->provider([], [$this->item('closures', 'capacity', 'r_second')]);

        $contributions = NavigationContributions::collect([$first, $second], Menu::Statistics);

        self::assertSame('r_first', $contributions->items['closures']->route);
        self::assertSame($first::class, $contributions->providers['closures']);
        self::assertSame(['Navigation id "closures" is registered more than once in menu "statistics".'], $contributions->problems);
        self::assertSame([], $contributions->orphans);
    }

    public function testItemsWithAnUnknownParentOrInACycleAreOrphans(): void
    {
        $contributions = NavigationContributions::collect([$this->provider([new NavGroup('capacity')], [
            $this->item('lost', 'nowhere', 'r_lost'),
            $this->item('a', 'b', 'r_a'),
            $this->item('b', 'a', 'r_b'),
            $this->item('fine', 'capacity', 'r_fine'),
        ])], Menu::Statistics);

        self::assertSame(['lost', 'a', 'b'], $contributions->orphans);
        self::assertSame([
            'Navigation item "lost" in menu "statistics" names the unknown parent "nowhere".',
            'Navigation item "a" in menu "statistics" is part of a parent cycle.',
            'Navigation item "b" in menu "statistics" is part of a parent cycle.',
        ], $contributions->problems);
        self::assertSame(NavigationVisibility::Orphaned, $contributions->visibility(static fn (): bool => true)['lost']);
        self::assertSame(NavigationVisibility::Visible, $contributions->visibility(static fn (): bool => true)['fine']);
    }

    public function testEveryEntryGetsTheReasonItIsShownOrHidden(): void
    {
        $contributions = NavigationContributions::collect([$this->provider(
            [new NavGroup('capacity'), new NavGroup('clinical'), new NavGroup('own')],
            [
                // capacity: a visible link, a denied group with a child, a route-less group whose only child is denied
                $this->item('emergency', 'capacity', 'r_emergency'),
                $this->item('closures', 'capacity', null, 'ROLE_CLOSURE_BETA'),
                $this->item('closures-overview', 'closures', 'r_closures'),
                $this->item('parts', 'capacity', null),
                $this->item('parts-hidden', 'parts', 'r_parts', 'ROLE_ENCOUNTER_BETA'),
                // clinical: only a denied entry, so the group is empty
                $this->item('encounter', 'clinical', 'r_encounter', 'ROLE_ENCOUNTER_BETA'),
            ],
        )], Menu::Statistics);

        $visibility = $contributions->visibility(static fn (string $attribute): bool => 'ROLE_USER' === $attribute);

        self::assertSame([
            'capacity' => NavigationVisibility::Visible,
            'clinical' => NavigationVisibility::Empty,
            'own' => NavigationVisibility::Empty,
            'emergency' => NavigationVisibility::Visible,
            'closures' => NavigationVisibility::Denied,
            'closures-overview' => NavigationVisibility::HiddenParent,
            'parts' => NavigationVisibility::NoVisibleChildren,
            'parts-hidden' => NavigationVisibility::Denied,
            'encounter' => NavigationVisibility::Denied,
        ], $this->sortedLike(['capacity', 'clinical', 'own', 'emergency', 'closures', 'closures-overview', 'parts', 'parts-hidden', 'encounter'], $visibility));
    }

    /**
     * @param list<string>                        $order
     * @param array<string, NavigationVisibility> $visibility
     *
     * @return array<string, NavigationVisibility>
     */
    private function sortedLike(array $order, array $visibility): array
    {
        self::assertEqualsCanonicalizing($order, array_keys($visibility), 'Every group and item has a visibility.');

        return array_merge(array_flip($order), $visibility);
    }

    private function item(string $id, string $parentId, ?string $route, ?string $granted = null): NavItem
    {
        return new NavItem($id, $parentId, new TranslatableMessage($id), $route, grantedAttribute: $granted);
    }

    /**
     * @param list<NavGroup> $groups
     * @param list<NavItem>  $items
     */
    private function provider(array $groups, array $items): NavigationProviderInterface
    {
        return new readonly class($groups, $items) implements NavigationProviderInterface {
            /**
             * @param list<NavGroup> $groups
             * @param list<NavItem>  $items
             */
            public function __construct(private array $groups, private array $items)
            {
            }

            #[\Override]
            public function groups(Menu $menu): array
            {
                return Menu::Statistics === $menu ? $this->groups : [];
            }

            #[\Override]
            public function items(Menu $menu): array
            {
                return Menu::Statistics === $menu ? $this->items : [];
            }
        };
    }
}
