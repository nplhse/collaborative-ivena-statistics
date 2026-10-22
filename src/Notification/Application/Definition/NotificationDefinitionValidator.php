<?php

declare(strict_types=1);

namespace App\Notification\Application\Definition;

use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Checks a single definition against the rules of ADR 016. Used by the compiler pass,
 * so an invalid definition fails the container build.
 */
final class NotificationDefinitionValidator
{
    /**
     * Recovery re-publishes outcomes of this window; duplicate protection must last longer.
     */
    public const string RECONCILIATION_WINDOW = 'PT24H';

    /**
     * Channels the publisher can deliver today. External channels are added together with their delivery.
     */
    public const array DELIVERABLE_CHANNELS = [NotificationChannel::InApp, NotificationChannel::Toast];

    private const string TYPE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /**
     * @return list<string> violations; empty when the definition is valid
     */
    public function validate(NotificationDefinition $definition): array
    {
        $errors = [];

        if (1 !== preg_match(self::TYPE_PATTERN, $definition->type)) {
            $errors[] = 'the type must be a lowercase dotted identifier such as "import.run_finished"';
        }

        if ([] === $definition->availableChannels) {
            $errors[] = 'at least one channel must be available';
        }

        foreach (['defaultChannels' => $definition->defaultChannels, 'mandatoryChannels' => $definition->mandatoryChannels] as $name => $channels) {
            foreach ($channels as $channel) {
                if (!\in_array($channel, $definition->availableChannels, true)) {
                    $errors[] = sprintf('%s contains "%s", which is not in availableChannels', $name, $channel->value);
                }

                if (!\in_array($channel, self::DELIVERABLE_CHANNELS, true)) {
                    $errors[] = sprintf('%s contains "%s", which cannot be delivered yet', $name, $channel->value);
                }
            }
        }

        if (\in_array(NotificationChannel::Toast, $definition->availableChannels, true)
            && !\in_array(NotificationChannel::InApp, $definition->availableChannels, true)) {
            $errors[] = 'toast is available without in_app';
        }

        if (\in_array(NotificationChannel::Toast, $definition->defaultChannels, true)
            && !\in_array(NotificationChannel::InApp, $definition->defaultChannels, true)) {
            $errors[] = 'toast is a default channel without in_app';
        }

        if (\in_array(NotificationChannel::Toast, $definition->mandatoryChannels, true)) {
            $errors[] = 'toast is best effort and cannot be mandatory';
        }

        if (!$definition->configurable && [] === $definition->defaultChannels && [] === $definition->mandatoryChannels) {
            $errors[] = 'a non-configurable type needs a default or mandatory channel';
        }

        return [...$errors, ...$this->validateDeduplicationTtl($definition->deduplicationTtl)];
    }

    /**
     * @return list<string>
     */
    private function validateDeduplicationTtl(string $ttl): array
    {
        try {
            $interval = new \DateInterval($ttl);
        } catch (\Exception) {
            return [sprintf('deduplicationTtl "%s" is not an ISO 8601 duration', $ttl)];
        }

        $reference = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
        $window = new \DateInterval(self::RECONCILIATION_WINDOW);

        if ($reference->add($interval) <= $reference->add($window)) {
            return [sprintf('deduplicationTtl "%s" must be longer than the reconciliation window of %s', $ttl, self::RECONCILIATION_WINDOW)];
        }

        return [];
    }
}
