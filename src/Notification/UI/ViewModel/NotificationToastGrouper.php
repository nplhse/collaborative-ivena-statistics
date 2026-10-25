<?php

declare(strict_types=1);

namespace App\Notification\UI\ViewModel;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Application\Definition\UnknownNotificationTypeException;
use App\Notification\Domain\Enum\NotificationPriority;
use App\Notification\UI\Http\Controller\NotificationInboxController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Collapses toasts of one poll answer whose types share a toast group into one toast (#720), for example
 * several finished imports into "5 imports processed, 1 failed". Presentation only: the inbox entries stay
 * separate. The grouped toast takes the place of the group's first toast and its highest priority, so a
 * failure keeps the warning colour and stays until it is closed.
 */
final readonly class NotificationToastGrouper
{
    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        private NotificationRegistry $registry,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<array{toast: array<string, mixed>, type: string, priority: NotificationPriority}> $toasts in display order
     *
     * @return list<array{toast: array<string, mixed>, priority: NotificationPriority}>
     */
    public function group(array $toasts): array
    {
        $members = [];
        foreach ($toasts as $index => $toast) {
            $group = $this->definition($toast['type'])?->toastGroup;
            if (null !== $group) {
                $members[$group][] = $index;
            }
        }

        $result = [];
        $handled = [];
        foreach ($toasts as $index => $toast) {
            if (isset($handled[$index])) {
                continue;
            }

            $definition = $this->definition($toast['type']);
            $group = $definition?->toastGroup;
            if (!$definition instanceof NotificationDefinition || null === $group || \count($members[$group]) < 2) {
                $result[] = ['toast' => $toast['toast'], 'priority' => $toast['priority']];

                continue;
            }

            $groupToasts = array_map(static fn (int $member): array => $toasts[$member], $members[$group]);
            foreach ($members[$group] as $member) {
                $handled[$member] = true;
            }
            $result[] = $this->grouped($group, $definition->toastGroupRoute, $definition->translationDomain, $groupToasts);
        }

        return $result;
    }

    /**
     * @param list<array{toast: array<string, mixed>, type: string, priority: NotificationPriority}> $toasts
     *
     * @return array{toast: array<string, mixed>, priority: NotificationPriority}
     */
    private function grouped(string $group, ?string $route, string $domain, array $toasts): array
    {
        // Counts per type, e.g. {count: 6, import_completed: 5, import_failed: 1}.
        $parameters = ['count' => \count($toasts)];
        $priority = NotificationPriority::Low;
        foreach ($toasts as $toast) {
            $key = str_replace('.', '_', $toast['type']);
            $parameters[$key] = ($parameters[$key] ?? 0) + 1;
            if ($this->rank($toast['priority']) > $this->rank($priority)) {
                $priority = $toast['priority'];
            }
        }
        foreach ($this->registry->definitions() as $definition) {
            if ($group === $definition->toastGroup) {
                $parameters[str_replace('.', '_', $definition->type)] ??= 0;
            }
        }

        return [
            'toast' => [
                'id' => null,
                'title' => $this->translator->trans('notification.toast_group.'.$group.'.title', $parameters, $domain),
                'message' => $this->translator->trans('notification.toast_group.'.$group.'.message', $parameters, $domain),
                'url' => null !== $route
                    ? $this->urlGenerator->generate($route)
                    : $this->urlGenerator->generate('app_notification_index', ['filter' => NotificationInboxController::FILTER_UNREAD]),
            ],
            'priority' => $priority,
        ];
    }

    /**
     * Entries of types that no longer exist are shown as they are.
     */
    private function definition(string $type): ?NotificationDefinition
    {
        try {
            return $this->registry->get($type);
        } catch (UnknownNotificationTypeException) {
            return null;
        }
    }

    private function rank(NotificationPriority $priority): int
    {
        return match ($priority) {
            NotificationPriority::Low => 0,
            NotificationPriority::Normal => 1,
            NotificationPriority::High => 2,
            NotificationPriority::Urgent => 3,
        };
    }
}
