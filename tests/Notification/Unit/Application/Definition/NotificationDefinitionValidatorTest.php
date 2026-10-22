<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Definition;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Definition\NotificationDefinitionValidator;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationDefinitionValidatorTest extends TestCase
{
    public function testAcceptsValidDefinition(): void
    {
        $definition = $this->definition(defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast], availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast]);

        self::assertSame([], new NotificationDefinitionValidator()->validate($definition));
    }

    /**
     * @param list<NotificationChannel> $defaultChannels
     * @param list<NotificationChannel> $availableChannels
     * @param list<NotificationChannel> $mandatoryChannels
     */
    #[DataProvider('invalidDefinitions')]
    public function testRejectsInvalidDefinition(
        string $expectedError,
        string $type = 'test.example',
        array $defaultChannels = [NotificationChannel::InApp],
        array $availableChannels = [NotificationChannel::InApp],
        array $mandatoryChannels = [],
        bool $configurable = true,
        string $deduplicationTtl = 'P7D',
    ): void {
        $errors = new NotificationDefinitionValidator()->validate($this->definition(type: $type, defaultChannels: $defaultChannels, availableChannels: $availableChannels, mandatoryChannels: $mandatoryChannels, configurable: $configurable, deduplicationTtl: $deduplicationTtl));

        self::assertNotSame([], $errors);
        self::assertStringContainsString($expectedError, implode(' | ', $errors));
    }

    /**
     * @return iterable<string, array<string, mixed>>
     */
    public static function invalidDefinitions(): iterable
    {
        yield 'type without dot' => ['expectedError' => 'lowercase dotted identifier', 'type' => 'example'];
        yield 'type with uppercase' => ['expectedError' => 'lowercase dotted identifier', 'type' => 'Import.Finished'];
        yield 'no available channel' => ['expectedError' => 'at least one channel', 'defaultChannels' => [], 'availableChannels' => []];
        yield 'default outside available' => [
            'expectedError' => 'defaultChannels contains "toast", which is not in availableChannels',
            'defaultChannels' => [NotificationChannel::InApp, NotificationChannel::Toast],
        ];
        yield 'mandatory outside available' => [
            'expectedError' => 'mandatoryChannels contains "email", which is not in availableChannels',
            'mandatoryChannels' => [NotificationChannel::Email],
        ];
        yield 'default not deliverable yet' => [
            'expectedError' => 'defaultChannels contains "email", which cannot be delivered yet',
            'defaultChannels' => [NotificationChannel::Email],
            'availableChannels' => [NotificationChannel::InApp, NotificationChannel::Email],
        ];
        yield 'toast available without in-app' => [
            'expectedError' => 'toast is available without in_app',
            'defaultChannels' => [],
            'availableChannels' => [NotificationChannel::Toast],
        ];
        yield 'toast default without in-app' => [
            'expectedError' => 'toast is a default channel without in_app',
            'defaultChannels' => [NotificationChannel::Toast],
            'availableChannels' => [NotificationChannel::InApp, NotificationChannel::Toast],
        ];
        yield 'mandatory toast' => [
            'expectedError' => 'toast is best effort and cannot be mandatory',
            'availableChannels' => [NotificationChannel::InApp, NotificationChannel::Toast],
            'mandatoryChannels' => [NotificationChannel::Toast],
        ];
        yield 'non-configurable without channels' => [
            'expectedError' => 'non-configurable type needs a default or mandatory channel',
            'defaultChannels' => [],
            'configurable' => false,
        ];
        yield 'ttl not iso 8601' => ['expectedError' => 'is not an ISO 8601 duration', 'deduplicationTtl' => '7 days'];
        yield 'ttl equal to reconciliation window' => ['expectedError' => 'must be longer than the reconciliation window', 'deduplicationTtl' => 'PT24H'];
        yield 'ttl shorter than reconciliation window' => ['expectedError' => 'must be longer than the reconciliation window', 'deduplicationTtl' => 'PT1H'];
    }

    /**
     * @param list<NotificationChannel> $defaultChannels
     * @param list<NotificationChannel> $availableChannels
     * @param list<NotificationChannel> $mandatoryChannels
     */
    private function definition(
        string $type = 'test.example',
        array $defaultChannels = [NotificationChannel::InApp],
        array $availableChannels = [NotificationChannel::InApp],
        array $mandatoryChannels = [],
        bool $configurable = true,
        string $deduplicationTtl = 'P7D',
    ): NotificationDefinition {
        return new NotificationDefinition(
            type: $type,
            category: NotificationCategory::Announcements,
            defaultChannels: $defaultChannels,
            availableChannels: $availableChannels,
            configurable: $configurable,
            mandatoryChannels: $mandatoryChannels,
            deduplicationTtl: $deduplicationTtl,
        );
    }
}
