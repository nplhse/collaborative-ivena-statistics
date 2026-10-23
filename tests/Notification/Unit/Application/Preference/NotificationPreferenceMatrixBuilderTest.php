<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Preference;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Preference\NotificationCategoryPreferences;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Application\Preference\NotificationPreferenceControl;
use App\Notification\Application\Preference\NotificationPreferenceControlKind;
use App\Notification\Application\Preference\NotificationPreferenceMatrix;
use App\Notification\Application\Preference\NotificationPreferenceMatrixBuilder;
use App\Notification\Application\Preference\NotificationPreferenceSet;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Tests\Support\Notification\Type\FixedTestNotification;
use App\Tests\Support\Notification\Type\InAppTestNotification;
use App\Tests\Support\Notification\Type\MandatoryInAppTestNotification;
use App\Tests\Support\Notification\Type\OptInTestNotification;
use App\Tests\Support\Notification\Type\RoleScopedTestNotification;
use App\Tests\Support\Notification\Type\ToastTestNotification;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

final class NotificationPreferenceMatrixBuilderTest extends TestCase
{
    public function testGroupsOfferedTypesByCategoryAndChannel(): void
    {
        $matrix = $this->build(['ROLE_USER'], NotificationPreferenceSet::fromOverrides([]));

        self::assertSame([NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast], $matrix->channels);
        self::assertSame(
            [NotificationCategory::Account, NotificationCategory::Announcements],
            array_map(static fn (NotificationCategoryPreferences $category): NotificationCategory => $category->category, $matrix->categories),
            'categories follow the enum order; role-scoped types are not offered without the role',
        );

        $announcements = $matrix->categories[1];
        self::assertSame(['test.in_app', 'test.toast', 'test.opt_in', 'test.fixed'], array_map(
            static fn (\App\Notification\Application\Preference\NotificationTypePreferences $type): string => $type->definition->type,
            $announcements->types,
        ));
    }

    public function testCategoryControlsCoverConfigurableTypes(): void
    {
        $matrix = $this->build(['ROLE_USER'], NotificationPreferenceSet::fromOverrides([]));
        [$account, $announcements] = $matrix->categories;

        $this->assertControl($account->controls['in_app'], NotificationPreferenceControlKind::Unavailable, null, null);
        $this->assertControl($account->controls['email'], NotificationPreferenceControlKind::Unavailable, null, null);
        $this->assertControl($account->controls['toast'], NotificationPreferenceControlKind::Configurable, null, false);

        $this->assertControl($announcements->controls['in_app'], NotificationPreferenceControlKind::Configurable, null, null, 'defaults differ between types');
        $this->assertControl($announcements->controls['email'], NotificationPreferenceControlKind::Configurable, null, false);
        $this->assertControl($announcements->controls['toast'], NotificationPreferenceControlKind::Configurable, null, true, 'non-configurable types do not count');
    }

    public function testTypeControlsShowMandatoryFixedAndUnavailableChannels(): void
    {
        $matrix = $this->build(['ROLE_USER'], NotificationPreferenceSet::fromOverrides([]));
        $mandatory = $matrix->categories[0]->types[0];
        $fixed = $matrix->categories[1]->types[3];

        $this->assertControl($mandatory->controls['in_app'], NotificationPreferenceControlKind::Mandatory, null, true);
        self::assertTrue($mandatory->controls['in_app']->isEffectivelyEnabled());
        $this->assertControl($mandatory->controls['email'], NotificationPreferenceControlKind::Unavailable, null, false);
        self::assertFalse($mandatory->controls['email']->isEffectivelyEnabled());
        $this->assertControl($mandatory->controls['toast'], NotificationPreferenceControlKind::Configurable, null, false);

        $this->assertControl($fixed->controls['in_app'], NotificationPreferenceControlKind::Fixed, null, true);
        $this->assertControl($fixed->controls['toast'], NotificationPreferenceControlKind::Fixed, null, false);
    }

    public function testTypeControlsInheritTheCategoryOverride(): void
    {
        $matrix = $this->build(['ROLE_USER'], NotificationPreferenceSet::fromOverrides([
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::InApp, false),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.opt_in', NotificationChannel::InApp, true),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.fixed', NotificationChannel::InApp, false),
        ]));
        $announcements = $matrix->categories[1];

        $this->assertControl($announcements->controls['in_app'], NotificationPreferenceControlKind::Configurable, false, null);
        self::assertFalse($announcements->controls['in_app']->isEffectivelyEnabled());
        $this->assertControl($announcements->types[0]->controls['in_app'], NotificationPreferenceControlKind::Configurable, null, false);
        $this->assertControl($announcements->types[2]->controls['in_app'], NotificationPreferenceControlKind::Configurable, true, false);
        self::assertTrue($announcements->types[2]->controls['in_app']->isEffectivelyEnabled());
        $this->assertControl($announcements->types[3]->controls['in_app'], NotificationPreferenceControlKind::Fixed, null, true, 'overrides of non-configurable types are ignored');
    }

    public function testConfigurableControlsListEveryEditableCell(): void
    {
        $matrix = $this->build(['ROLE_USER'], NotificationPreferenceSet::fromOverrides([]));

        self::assertSame([
            'category-account-toast',
            'type-test-mandatory_in_app-toast',
            'category-announcements-in_app',
            'category-announcements-email',
            'category-announcements-toast',
            'type-test-in_app-in_app',
            'type-test-in_app-email',
            'type-test-toast-in_app',
            'type-test-toast-toast',
            'type-test-opt_in-in_app',
        ], array_map(static fn (NotificationPreferenceControl $control): string => $control->fieldName(), $matrix->configurableControls()));
    }

    public function testRoleScopedTypesAreOfferedThroughTheRoleHierarchy(): void
    {
        $matrix = $this->build(['ROLE_SUPER_ADMIN'], NotificationPreferenceSet::fromOverrides([]));

        self::assertSame(NotificationCategory::Administration, $matrix->categories[2]->category);
        self::assertSame('test.role_scoped', $matrix->categories[2]->types[0]->definition->type);
    }

    /**
     * @param list<string> $roles
     */
    private function build(array $roles, NotificationPreferenceSet $preferences): NotificationPreferenceMatrix
    {
        $builder = new NotificationPreferenceMatrixBuilder(
            new NotificationRegistry([
                'test.in_app' => InAppTestNotification::class,
                'test.toast' => ToastTestNotification::class,
                'test.opt_in' => OptInTestNotification::class,
                'test.fixed' => FixedTestNotification::class,
                'test.mandatory_in_app' => MandatoryInAppTestNotification::class,
                'test.role_scoped' => RoleScopedTestNotification::class,
            ]),
            new RoleHierarchy(['ROLE_SUPER_ADMIN' => ['ROLE_ADMIN']]),
        );
        $user = new User();
        $user->setRoles($roles);

        return $builder->build($user, $preferences);
    }

    private function assertControl(
        NotificationPreferenceControl $control,
        NotificationPreferenceControlKind $kind,
        ?bool $override,
        ?bool $inherited,
        string $message = '',
    ): void {
        self::assertSame(
            ['kind' => $kind, 'override' => $override, 'inherited' => $inherited],
            ['kind' => $control->kind, 'override' => $control->override, 'inherited' => $control->inherited],
            $message,
        );
    }
}
