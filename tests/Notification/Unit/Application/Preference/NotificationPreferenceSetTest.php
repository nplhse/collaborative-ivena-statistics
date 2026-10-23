<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Preference;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Preference\NotificationPreferenceChange;
use App\Notification\Application\Preference\NotificationPreferenceControl;
use App\Notification\Application\Preference\NotificationPreferenceControlKind;
use App\Notification\Application\Preference\NotificationPreferenceSet;
use App\Notification\Application\Preference\NotificationPreferenceState;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPreferenceScope;
use App\Tests\Support\Notification\Type\FixedTestNotification;
use App\Tests\Support\Notification\Type\ToastTestNotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationPreferenceSetTest extends TestCase
{
    public function testSplitsOverridesOfADefinitionIntoTypeAndCategory(): void
    {
        $set = NotificationPreferenceSet::fromOverrides([
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'announcements', NotificationChannel::InApp, false),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::Toast, true),
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.toast', NotificationChannel::InApp, null),
            new NotificationPreferenceChange(NotificationPreferenceScope::Category, 'imports', NotificationChannel::Toast, false),
        ]);

        $overrides = $set->forDefinition($this->definition(ToastTestNotification::class));

        self::assertFalse($overrides->forCategory(NotificationChannel::InApp));
        self::assertNull($overrides->forCategory(NotificationChannel::Toast));
        self::assertTrue($overrides->forType(NotificationChannel::Toast));
        self::assertNull($overrides->forType(NotificationChannel::InApp), 'changes without a value are no overrides');
    }

    public function testNonConfigurableDefinitionsGetNoOverrides(): void
    {
        $set = NotificationPreferenceSet::fromOverrides([
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'test.fixed', NotificationChannel::InApp, false),
        ]);

        self::assertFalse($set->get(NotificationPreferenceScope::Type, 'test.fixed', NotificationChannel::InApp));
        self::assertNull($set->forDefinition($this->definition(FixedTestNotification::class))->forType(NotificationChannel::InApp));
        self::assertNull(NotificationPreferenceSet::fromOverrides([])->get(NotificationPreferenceScope::Type, 'test.fixed', NotificationChannel::InApp));
    }

    #[DataProvider('states')]
    public function testStatesMapToOverrides(?bool $override, NotificationPreferenceState $state): void
    {
        self::assertSame($state, NotificationPreferenceState::fromOverride($override));
        self::assertSame($override, $state->toOverride());
    }

    /**
     * @return iterable<string, array{?bool, NotificationPreferenceState}>
     */
    public static function states(): iterable
    {
        yield 'default' => [null, NotificationPreferenceState::Default];
        yield 'on' => [true, NotificationPreferenceState::On];
        yield 'off' => [false, NotificationPreferenceState::Off];
    }

    public function testControlBuildsFieldNameStateAndChange(): void
    {
        $control = new NotificationPreferenceControl(
            NotificationPreferenceScope::Type,
            'import.batch_finished',
            NotificationChannel::Email,
            NotificationPreferenceControlKind::Configurable,
            false,
            true,
        );

        self::assertSame('type-import-batch_finished-email', $control->fieldName());
        self::assertSame(NotificationPreferenceState::Off, $control->state());
        self::assertFalse($control->isEffectivelyEnabled());
        self::assertEquals(
            new NotificationPreferenceChange(NotificationPreferenceScope::Type, 'import.batch_finished', NotificationChannel::Email, null),
            $control->change(NotificationPreferenceState::Default),
        );
    }

    /**
     * @param class-string $class
     */
    private function definition(string $class): NotificationDefinition
    {
        $definition = NotificationRegistry::readDefinition($class);
        self::assertNotNull($definition);

        return $definition;
    }
}
