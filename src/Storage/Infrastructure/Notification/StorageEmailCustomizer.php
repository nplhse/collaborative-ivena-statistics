<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Notification;

use App\Notification\Application\Contract\NotificationEmailCustomizerInterface;
use App\Notification\Application\Delivery\NotificationEmailContent;
use App\Storage\Application\Notification\StorageNotificationParameters;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The emails of the storage alert and the daily storage report: the translated title and message plus the
 * values of the measurement as a table, in the recipient's locale. Built from the stored parameters only.
 *
 * @psalm-suppress UnusedClass Tagged via NotificationEmailCustomizerInterface.
 */
final readonly class StorageEmailCustomizer implements NotificationEmailCustomizerInterface
{
    public const string TEMPLATE = '@Storage/email/storage_notification.html.twig';

    private const string DOMAIN = 'notification';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function supports(string $type): bool
    {
        return \in_array($type, [StorageCapacityAlertNotification::TYPE, StorageDailyReportNotification::TYPE], true);
    }

    #[\Override]
    public function customize(NotificationEmailContent $content, User $recipient): void
    {
        $parameters = $content->parameters;
        $rows = StorageDailyReportNotification::TYPE === $content->type
            ? $this->reportRows($parameters, $content->locale)
            : $this->alertRows($parameters, $content->locale);

        $content->htmlTemplate = self::TEMPLATE;
        $content->context = [
            'title' => $content->context['title'] ?? $content->subject,
            'message' => $content->context['message'] ?? '',
            'actionUrl' => $content->context['actionUrl'] ?? null,
            'inboxUrl' => $content->context['inboxUrl'] ?? null,
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return list<array{label: string, value: string}>
     */
    private function alertRows(array $parameters, string $locale): array
    {
        return [
            $this->row('storage.email.level', $this->trans('storage.email.level_change', $parameters, $locale), $locale),
            ...$this->capacityRows($parameters, $locale),
        ];
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return list<array{label: string, value: string}>
     */
    private function reportRows(array $parameters, string $locale): array
    {
        $unavailable = $this->trans('storage.email.unavailable', [], $locale);

        $categories = [];
        for ($index = 1; $index <= StorageNotificationParameters::TOP_CATEGORIES; ++$index) {
            if (isset($parameters['category'.$index])) {
                $categories[] = $this->trans('storage.email.category_value', [
                    'category' => $parameters['category'.$index],
                    'size' => $parameters['category'.$index.'Size'] ?? '',
                ], $locale);
            }
        }

        $rows = [
            $this->row('storage.email.level', $this->trans('storage.email.level_value', $parameters, $locale), $locale),
            $this->row('storage.email.used', $this->text($parameters['used'] ?? null, $unavailable), $locale),
            $this->row('storage.email.database', $this->text($parameters['database'] ?? null, $unavailable), $locale),
            ...$this->capacityRows($parameters, $locale),
            $this->row('storage.email.change', $this->trans('storage.email.change_value', $parameters, $locale), $locale),
        ];

        if ([] !== $categories) {
            $rows[] = $this->row('storage.email.largest_categories', implode(' · ', $categories), $locale);
        }

        if ('deployer' === ($parameters['mode'] ?? null)) {
            $rows[] = $this->row('storage.email.previous_releases', $this->trans('storage.email.previous_releases_value', [
                'count' => $parameters['previousReleases'] ?? 0,
                'size' => $parameters['previousReleasesSize'] ?? '',
            ], $locale), $locale);
            $rows[] = $this->row('storage.email.shared', $this->text($parameters['shared'] ?? null, $unavailable), $locale);
        }

        return $rows;
    }

    /**
     * Capacity reference, the usage compared with it and what remains; only the reference without a usage.
     *
     * @param array<string, scalar|null> $parameters
     *
     * @return list<array{label: string, value: string}>
     */
    private function capacityRows(array $parameters, string $locale): array
    {
        $noReference = $this->trans('storage.email.no_reference', $parameters, $locale);
        $rows = [[
            'label' => $this->trans('storage.email.capacity', $parameters, $locale),
            'value' => $this->text($parameters['capacity'] ?? null, $noReference),
        ]];

        if (!\in_array($parameters['reference'] ?? null, ['budget', 'filesystem'], true)) {
            $rows[] = $this->row('storage.email.remaining', $noReference, $locale);

            return $rows;
        }

        $rows[] = $this->row('storage.email.compared_used', $this->trans('storage.email.compared_used_value', $parameters, $locale), $locale);
        $rows[] = $this->row('storage.email.remaining', $this->trans('storage.email.remaining_value', $parameters, $locale), $locale);

        return $rows;
    }

    /**
     * @return array{label: string, value: string}
     */
    private function row(string $labelKey, string $value, string $locale): array
    {
        return ['label' => $this->trans($labelKey, [], $locale), 'value' => $value];
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function trans(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans($key, $parameters, self::DOMAIN, $locale);
    }

    private function text(bool|float|int|string|null $value, string $fallback): string
    {
        return null === $value ? $fallback : (string) $value;
    }
}
