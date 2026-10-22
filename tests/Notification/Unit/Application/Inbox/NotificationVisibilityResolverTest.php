<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Inbox;

use App\Notification\Application\Contract\NotificationHospitalAccessInterface;
use App\Notification\Application\Inbox\NotificationVisibility;
use App\Notification\Application\Inbox\NotificationVisibilityResolver;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Tests\Support\Notification\InboxEntries;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class NotificationVisibilityResolverTest extends TestCase
{
    public function testAppliesScopeRules(): void
    {
        $access = new class implements NotificationHospitalAccessInterface {
            /** @var list<list<int>> */
            public array $calls = [];

            public function filterAccessible(User $viewer, array $hospitalIds): array
            {
                $this->calls[] = $hospitalIds;

                return array_values(array_intersect($hospitalIds, [10]));
            }
        };
        $resolver = new NotificationVisibilityResolver($access, new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_PARTICIPANT']]));
        $viewer = new User();
        $viewer->setRoles(['ROLE_ADMIN']);

        $visibility = $resolver->resolve($viewer, [
            InboxEntries::entry(1, NotificationAccessScope::None),
            InboxEntries::entry(2, NotificationAccessScope::Hospital, hospitalId: 10),
            InboxEntries::entry(3, NotificationAccessScope::Hospital, hospitalId: 20),
            InboxEntries::entry(4, NotificationAccessScope::Hospital, hospitalId: 10),
            InboxEntries::entry(5, NotificationAccessScope::Hospital),
            InboxEntries::entry(6, NotificationAccessScope::Role, role: 'ROLE_PARTICIPANT'),
            InboxEntries::entry(7, NotificationAccessScope::Role, role: 'ROLE_FEEDBACK_RECIPIENT'),
            InboxEntries::entry(8, NotificationAccessScope::Role),
        ]);

        self::assertSame([
            1 => NotificationVisibility::Visible,
            2 => NotificationVisibility::Visible,
            3 => NotificationVisibility::Redacted,
            4 => NotificationVisibility::Visible,
            5 => NotificationVisibility::Redacted,
            6 => NotificationVisibility::Visible,
            7 => NotificationVisibility::Hidden,
            8 => NotificationVisibility::Hidden,
        ], $visibility);
        self::assertSame([[10, 20]], $access->calls, 'hospital access is checked once per batch with distinct ids');
    }

    public function testSkipsHospitalLookupWithoutHospitalScopedEntries(): void
    {
        $access = new class implements NotificationHospitalAccessInterface {
            public function filterAccessible(User $viewer, array $hospitalIds): array
            {
                throw new \LogicException('must not be called');
            }
        };
        $resolver = new NotificationVisibilityResolver($access, new RoleHierarchy([]));

        self::assertSame(
            [1 => NotificationVisibility::Visible],
            $resolver->resolve(new User(), [InboxEntries::entry(1, NotificationAccessScope::None)]),
        );
    }
}
