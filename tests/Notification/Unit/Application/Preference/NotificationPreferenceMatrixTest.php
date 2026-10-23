<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Preference;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Application\Preference\NotificationPreferenceMatrix;
use App\Notification\Application\Preference\NotificationPreferenceMatrixBuilder;
use App\Notification\Application\Preference\NotificationPreferenceSet;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Tests\Support\Notification\Type\FixedTestNotification;
use App\Tests\Support\Notification\Type\InAppTestNotification;
use App\Tests\Support\Notification\Type\MandatoryInAppTestNotification;
use App\Tests\Support\Notification\Type\OptInTestNotification;
use App\Tests\Support\Notification\Type\ToastTestNotification;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * Save rules for the checkboxes of the settings page (ADR 016, "Preferences and resolution").
 */
final class NotificationPreferenceMatrixTest extends TestCase
{
    public function testUnchangedCheckboxesChangeNothing(): void
    {
        $matrix = $this->matrix([
            $this->change(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::Toast, false),
        ]);

        self::assertSame([], $matrix->changesFor($this->shown($matrix)));
    }

    public function testCheckedCategoryStoresAnOverrideAndUntouchedTypesFollowIt(): void
    {
        $matrix = $this->matrix([]);
        $checked = $this->shown($matrix);
        $checked['category-announcements-email'] = true;

        self::assertEquals(
            [$this->change(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::Email, true)],
            $matrix->changesFor($checked),
        );
    }

    public function testTypesTickedTogetherWithTheirCategoryStoreNoOverride(): void
    {
        $matrix = $this->matrix([]);
        $checked = $this->shown($matrix);
        $checked['category-announcements-email'] = true;
        $checked['type-test-in_app-email'] = true;

        self::assertEquals(
            [$this->change(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::Email, true)],
            $matrix->changesFor($checked),
            'what the page script does when the category is ticked',
        );
    }

    public function testChangedTypeStoresAnOverrideAndResettingItRemovesIt(): void
    {
        $matrix = $this->matrix([]);
        $checked = $this->shown($matrix);
        $checked['type-test-in_app-in_app'] = false;

        self::assertEquals(
            [$this->change(NotificationPreferenceScope::Type, 'test.in_app', NotificationChannel::InApp, false)],
            $matrix->changesFor($checked),
        );

        $stored = $this->matrix([$this->change(NotificationPreferenceScope::Type, 'test.in_app', NotificationChannel::InApp, false)]);
        $checked = $this->shown($stored);
        $checked['type-test-in_app-in_app'] = true;

        self::assertEquals(
            [$this->change(NotificationPreferenceScope::Type, 'test.in_app', NotificationChannel::InApp, null)],
            $stored->changesFor($checked),
        );
    }

    public function testCategoryBackToItsDefaultAlsoDropsTypeSettingsThatNowMatchIt(): void
    {
        $matrix = $this->matrix([
            $this->change(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::Toast, false),
            $this->change(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::Toast, true),
        ]);
        $checked = $this->shown($matrix);
        self::assertFalse($checked['category-announcements-toast']);
        self::assertTrue($checked['type-test-toast-toast']);

        $checked['category-announcements-toast'] = true;

        self::assertEquals(
            [
                $this->change(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::Toast, null),
                $this->change(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::Toast, null),
            ],
            $matrix->changesFor($checked),
            'the type was on, the category is on again: the type follows its category from now on',
        );
    }

    public function testMixedCategoryStoresWhateverIsChosen(): void
    {
        $matrix = $this->matrix([]);
        $checked = $this->shown($matrix);
        self::assertFalse($checked['category-announcements-in_app'], 'types with different defaults show an unticked category');

        $checked['category-announcements-in_app'] = true;
        $checked['type-test-opt_in-in_app'] = true;

        self::assertEquals(
            [$this->change(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::InApp, true)],
            $matrix->changesFor($checked),
        );
    }

    public function testMissingFieldsCountAsUnchecked(): void
    {
        $matrix = $this->matrix([]);

        self::assertEquals(
            [$this->change(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::Toast, false)],
            array_values(array_filter(
                $matrix->changesFor([]),
                static fn (NotificationPreferenceChange $change): bool => NotificationPreferenceScope::Category === $change->scope,
            )),
            'the account toast was already off; only the announcements toast changes',
        );
    }

    /**
     * @param list<NotificationPreferenceChange> $stored
     */
    private function matrix(array $stored): NotificationPreferenceMatrix
    {
        $builder = new NotificationPreferenceMatrixBuilder(
            new NotificationRegistry([
                'test.in_app' => InAppTestNotification::class,
                'test.toast' => ToastTestNotification::class,
                'test.opt_in' => OptInTestNotification::class,
                'test.fixed' => FixedTestNotification::class,
                'test.mandatory_in_app' => MandatoryInAppTestNotification::class,
            ]),
            new RoleHierarchy([]),
        );

        return $builder->build(new User(), NotificationPreferenceSet::fromOverrides($stored));
    }

    /**
     * @return array<string, bool>
     */
    private function shown(NotificationPreferenceMatrix $matrix): array
    {
        $checked = [];
        foreach ($matrix->configurableControls() as $control) {
            $checked[$control->fieldName()] = $control->isChecked();
        }

        return $checked;
    }

    private function change(NotificationPreferenceScope $scope, string $key, NotificationChannel $channel, ?bool $enabled): NotificationPreferenceChange
    {
        return new NotificationPreferenceChange($scope, $key, $channel, $enabled);
    }
}
