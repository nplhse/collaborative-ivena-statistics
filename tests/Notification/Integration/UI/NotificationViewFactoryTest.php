<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\UI;

use App\Notification\Application\Contract\NotificationHospitalAccessInterface;
use App\Notification\Application\Inbox\NotificationVisibilityResolver;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\UI\ViewModel\NotificationViewFactory;
use App\Tests\Support\Notification\InboxEntries;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class NotificationViewFactoryTest extends KernelTestCase
{
    public function testBuildsViewsWithLinksRedactionAndHiddenEntries(): void
    {
        $factory = $this->factory(accessibleHospitals: [10]);
        $visibleWithRoute = InboxEntries::entry(1, route: 'app_notification_index');
        $unknownRoute = InboxEntries::entry(2, NotificationAccessScope::None, route: 'app_route_that_does_not_exist');
        $missingParameter = InboxEntries::entry(3, NotificationAccessScope::None, route: 'app_notification_open');
        $redacted = InboxEntries::entry(4, NotificationAccessScope::Hospital, hospitalId: 20, route: 'app_notification_index');
        $hidden = InboxEntries::entry(5, NotificationAccessScope::Role, role: 'ROLE_ADMIN');

        $views = $factory->build(new User(), [$visibleWithRoute, $unknownRoute, $missingParameter, $redacted, $hidden]);

        self::assertSame([1, 2, 3, 4], array_map(static fn (\App\Notification\UI\ViewModel\NotificationView $view): int => $view->id, $views));
        self::assertTrue($views[0]->hasLink);
        self::assertSame('test.example.title', $views[0]->titleKey);
        self::assertSame(['name' => 'Klinikum'], $views[0]->parameters);
        self::assertFalse($views[1]->hasLink, 'unknown route');
        self::assertFalse($views[2]->hasLink, 'missing route parameter');
        self::assertTrue($views[3]->isRedacted);
        self::assertFalse($views[3]->hasLink);
        self::assertSame([], $views[3]->parameters);
        self::assertSame(NotificationViewFactory::REDACTED_TITLE_KEY, $views[3]->titleKey);
        self::assertSame(NotificationViewFactory::REDACTED_DOMAIN, $views[3]->translationDomain);
    }

    public function testTargetUrlOnlyForVisibleEntries(): void
    {
        $factory = $this->factory(accessibleHospitals: [10]);

        self::assertSame('/notifications', $factory->targetUrl(new User(), InboxEntries::entry(1, NotificationAccessScope::Hospital, hospitalId: 10, route: 'app_notification_index')));
        self::assertNull($factory->targetUrl(new User(), InboxEntries::entry(2, NotificationAccessScope::Hospital, hospitalId: 20, route: 'app_notification_index')));
        self::assertNull($factory->targetUrl(new User(), InboxEntries::entry(3, NotificationAccessScope::None)));
    }

    /**
     * @param list<int> $accessibleHospitals
     */
    private function factory(array $accessibleHospitals): NotificationViewFactory
    {
        $access = new readonly class($accessibleHospitals) implements NotificationHospitalAccessInterface {
            /**
             * @param list<int> $accessible
             */
            public function __construct(private array $accessible)
            {
            }

            public function filterAccessible(User $viewer, array $hospitalIds): array
            {
                return array_values(array_intersect($hospitalIds, $this->accessible));
            }
        };

        return new NotificationViewFactory(
            new NotificationVisibilityResolver($access, new RoleHierarchy([])),
            self::getContainer()->get(UrlGeneratorInterface::class),
        );
    }
}
