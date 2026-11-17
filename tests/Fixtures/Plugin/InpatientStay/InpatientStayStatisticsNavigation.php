<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Plugin\InpatientStay;

use App\Shared\Application\Navigation\Menu\Menu;
use App\Shared\Application\Navigation\Menu\NavigationProviderInterface;
use App\Shared\Application\Navigation\Menu\NavItem;
use App\User\Domain\Security\UserRole;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Entry of the bundle's statistics page in the clinical group of the statistics sidebar (#1300).
 */
final readonly class InpatientStayStatisticsNavigation implements NavigationProviderInterface
{
    #[\Override]
    public function groups(Menu $menu): array
    {
        return [];
    }

    #[\Override]
    public function items(Menu $menu): array
    {
        if (Menu::Statistics !== $menu) {
            return [];
        }

        return [
            new NavItem(
                id: 'inpatient-stays',
                parentId: Menu::STATISTICS_GROUP_CLINICAL,
                label: new TranslatableMessage('inpatient_stay_test.statistics.title', [], 'inpatient_stay_test'),
                route: 'app_stats_inpatient_stays',
                grantedAttribute: UserRole::ENCOUNTER_BETA,
                weight: 90,
            ),
        ];
    }
}
