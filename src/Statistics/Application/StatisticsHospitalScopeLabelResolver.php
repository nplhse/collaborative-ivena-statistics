<?php

declare(strict_types=1);

namespace App\Statistics\Application;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StatisticsHospitalScopeLabelResolver
{
    public function __construct(
        private HospitalAccessInterface $hospitalAccess,
        private TranslatorInterface $translator,
    ) {
    }

    public function groupLabel(?User $user, ?string $locale = null): string
    {
        if ($user instanceof User && $this->hospitalAccess->isAdminHospitalScopeUser($user)) {
            return $this->translator->trans('stats.filter.scope.hospitals', [], null, $locale);
        }

        return $this->translator->trans('stats.filter.scope.my_hospitals', [], null, $locale);
    }
}
