<?php

declare(strict_types=1);

namespace App\Admin\Application\Service;

use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin labels for the latest acceptance of the terms of use (#1564): the accepted version and when, and whether it is
 * the configured current version. Returns escaped HTML for EasyAdmin `formatValue()`.
 */
final readonly class TermsAcceptanceLabelFormatter
{
    public function __construct(
        private TermsAcceptanceRepository $acceptances,
        private TermsVersion $termsVersion,
        private TranslatorInterface $translator,
    ) {
    }

    public function forUser(User $user): string
    {
        return $this->label($this->acceptances->latestFor($user));
    }

    /**
     * How many of these users accepted the configured version, or null while no version is configured.
     *
     * @param list<User> $users
     */
    public function currentVersionSummary(array $users): ?string
    {
        $current = $this->termsVersion->current();
        if (null === $current) {
            return null;
        }

        $accepted = 0;
        foreach ($users as $user) {
            if ($current === $this->acceptances->latestFor($user)?->getTermsVersion()) {
                ++$accepted;
            }
        }

        return htmlspecialchars($this->translator->trans('admin.terms_acceptance.current_summary', [
            'version' => $current,
            'accepted' => $accepted,
            'total' => \count($users),
        ], 'admin'), \ENT_QUOTES);
    }

    private function label(?TermsAcceptance $acceptance): string
    {
        if (!$acceptance instanceof TermsAcceptance) {
            return htmlspecialchars($this->translator->trans('admin.terms_acceptance.none', [], 'admin'), \ENT_QUOTES);
        }

        $version = $acceptance->getTermsVersion();
        $label = htmlspecialchars($this->translator->trans(
            null === $version ? 'admin.terms_acceptance.accepted_without_version' : 'admin.terms_acceptance.accepted',
            ['version' => $version, 'date' => $acceptance->getAcceptedAt()->format('d.m.Y H:i')],
            'admin',
        ), \ENT_QUOTES);

        $current = $this->termsVersion->current();
        if (null !== $current && $current !== $version) {
            $label .= ' <span class="badge badge-warning">'.htmlspecialchars($this->translator->trans('admin.terms_acceptance.not_current', [], 'admin'), \ENT_QUOTES).'</span>';
        }

        return $label;
    }
}
