<?php

declare(strict_types=1);

namespace App\Onboarding\Application\WhatsNew;

use App\Onboarding\Application\Dto\WhatsNewCardView;
use App\Onboarding\Application\Dto\WhatsNewItemView;
use App\Onboarding\Application\OnboardingStepCatalog;
use App\Onboarding\Domain\Entity\UserWhatsNewDismissal;
use App\Onboarding\Domain\Entity\WhatsNewRelease;
use App\Onboarding\Domain\Enum\WhatsNewItemKey;
use App\Onboarding\Infrastructure\Repository\UserWhatsNewDismissalRepository;
use App\Onboarding\Infrastructure\Repository\WhatsNewReleaseRepository;
use App\User\Domain\Entity\User;
use App\User\Domain\Security\UserRole;
use App\User\Infrastructure\Security\TwoFactor\TwoFactorAvailability;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The "What's new for you" card (#1485): what a release brings for accounts that existed before it was installed.
 * A release is shown when its row in `whats_new_release` exists (inserted by the release's migration), the account
 * is older than that row, the user has not hidden it, the display window is not over and an item applies.
 */
final readonly class WhatsNewService
{
    /**
     * Releases with their items, newest first. Add a release here together with the migration that inserts its row.
     *
     * @var array<string, list<WhatsNewItemKey>>
     */
    public const array RELEASES = [
        'v0.3' => [
            WhatsNewItemKey::DisplayName,
            WhatsNewItemKey::MyHospitals,
            WhatsNewItemKey::AllocationExport,
            WhatsNewItemKey::HelpGlossary,
            WhatsNewItemKey::TwoFactor,
            WhatsNewItemKey::SettingsOverview,
        ],
    ];

    public const int DISPLAY_DAYS = 60;

    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private WhatsNewReleaseRepository $releases,
        private UserWhatsNewDismissalRepository $dismissals,
        private OnboardingStepCatalog $stepCatalog,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private RoleHierarchyInterface $roleHierarchy,
        private TwoFactorAvailability $twoFactorAvailability,
    ) {
    }

    public function buildCardForUser(User $user): ?WhatsNewCardView
    {
        foreach (self::RELEASES as $releaseKey => $itemKeys) {
            $release = $this->visibleRelease($user, $releaseKey);
            if (!$release instanceof WhatsNewRelease) {
                continue;
            }

            $items = [];
            foreach ($itemKeys as $itemKey) {
                $item = $this->buildItem($user, $itemKey);
                if ($item instanceof WhatsNewItemView) {
                    $items[] = $item;
                }
            }

            if ([] !== $items) {
                return new WhatsNewCardView($releaseKey, $items);
            }
        }

        return null;
    }

    public function dismiss(User $user, string $releaseKey): void
    {
        if (!\array_key_exists($releaseKey, self::RELEASES) || !$this->releases->findOneByKey($releaseKey) instanceof WhatsNewRelease) {
            throw new NotFoundHttpException('Unknown release.');
        }

        if ($this->dismissals->hasDismissed($user, $releaseKey)) {
            return;
        }

        $this->dismissals->save(new UserWhatsNewDismissal($user, $releaseKey, $this->clock->now()));
    }

    private function visibleRelease(User $user, string $releaseKey): ?WhatsNewRelease
    {
        $release = $this->releases->findOneByKey($releaseKey);
        if (!$release instanceof WhatsNewRelease) {
            return null;
        }

        $installedAt = $release->getInstalledAt();
        if ($user->getCreatedAt() >= $installedAt) {
            return null;
        }

        if ($this->clock->now() >= $installedAt->modify(sprintf('+%d days', self::DISPLAY_DAYS))) {
            return null;
        }

        return $this->dismissals->hasDismissed($user, $releaseKey) ? null : $release;
    }

    private function buildItem(User $user, WhatsNewItemKey $itemKey): ?WhatsNewItemView
    {
        return match ($itemKey) {
            WhatsNewItemKey::DisplayName => $this->displayNameItem($user),
            WhatsNewItemKey::MyHospitals => $this->stepCatalog->hasClinicAccess($user)
                ? $this->item($itemKey, 'my_hospitals', 'app_hospitals_index')
                : null,
            // The export moved from the header into Tools (#1633); only for users who may export.
            WhatsNewItemKey::AllocationExport => $this->stepCatalog->hasExportPermission($user)
                ? $this->item($itemKey, 'allocation_export', 'app_tools_allocation_export')
                : null,
            // Glossary and data catalog in the help section (#1529) need the participant role.
            WhatsNewItemKey::HelpGlossary => $this->isParticipant($user)
                ? $this->item($itemKey, 'help_glossary', 'app_help_glossary_index')
                : null,
            // Two-factor sign-in (#1357), only where it can be set up and is not in use yet.
            WhatsNewItemKey::TwoFactor => $this->twoFactorAvailability->isAvailable() && !$user->isTotpAuthenticationEnabled()
                ? $this->item($itemKey, 'two_factor', 'app_settings_two_factor')
                : null,
            WhatsNewItemKey::SettingsOverview => $this->item($itemKey, 'settings_overview', 'app_settings_index'),
        };
    }

    private function isParticipant(User $user): bool
    {
        return \in_array(UserRole::PARTICIPANT, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true);
    }

    private function displayNameItem(User $user): WhatsNewItemView
    {
        $displayName = $user->getDisplayName();
        if (null === $displayName) {
            return $this->item(WhatsNewItemKey::DisplayName, 'display_name.missing', 'app_settings_account');
        }

        // Set by the user, an administrator or the migration: say which name others now see.
        return $this->item(WhatsNewItemKey::DisplayName, 'display_name.set', 'app_settings_account', ['name' => $displayName]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function item(WhatsNewItemKey $key, string $textKey, string $route, array $parameters = []): WhatsNewItemView
    {
        return new WhatsNewItemView(
            key: $key,
            title: new TranslatableMessage(sprintf('whats_new.items.%s.title', $textKey), $parameters, 'onboarding'),
            description: new TranslatableMessage(sprintf('whats_new.items.%s.description', $textKey), $parameters, 'onboarding'),
            actionUrl: $this->urlGenerator->generate($route),
            actionLabel: new TranslatableMessage(sprintf('whats_new.items.%s.action', $textKey), $parameters, 'onboarding'),
        );
    }
}
