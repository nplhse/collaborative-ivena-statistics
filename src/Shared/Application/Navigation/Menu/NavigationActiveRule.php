<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

/**
 * The rule by which {@see NavigationBuilder} chose the active entry of a menu (ADR 035), in the order the
 * builder tries them.
 */
enum NavigationActiveRule: string
{
    /** The current route is the entry's `route` (and its `routeParameters` match). */
    case Route = 'route';

    /** The current route is one of the entry's `detailRoutes` (and its `routeParameters` match). */
    case DetailRoute = 'detail_route';

    /** Primary menu only: the route belongs to the sidebar menu of this area. */
    case SidebarMenu = 'sidebar_menu';

    /** Primary menu only: the page names this area. */
    case Area = 'area';

    /** Primary menu only: the route starts with the entry's `areaRoutePrefix` (the longest one wins). */
    case AreaRoutePrefix = 'area_route_prefix';
}
