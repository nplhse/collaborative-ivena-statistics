<?php

declare(strict_types=1);

namespace App\Encounter\UI\Navigation;

use App\Allocation\Application\Hospital\MyHospitals\Action\MyHospitalAction;
use App\Allocation\Application\Hospital\MyHospitals\Action\MyHospitalActionProviderInterface;
use App\Encounter\Application\Overview\AggregateHospitalScope;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Adds "Encounter data" to the My Hospitals cards of the hospitals whose encounter analysis the user may
 * open (#1128): the hospitals of their encounter grants with VIEW_AGGREGATES, for administrators every
 * hospital with stored visits. Replaces the grant list of the removed encounter overview page. The scope
 * is read once per page.
 *
 * @psalm-suppress UnusedClass Tagged via MyHospitalActionProviderInterface.
 */
final readonly class EncounterMyHospitalActionProvider implements MyHospitalActionProviderInterface
{
    public const string KEY = 'encounter_data';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private AggregateHospitalScope $aggregateHospitalScope,
    ) {
    }

    #[\Override]
    public function actionsFor(array $hospitalIds): array
    {
        if ([] === $hospitalIds || !$this->authorizationChecker->isGranted('ROLE_ENCOUNTER_BETA')) {
            return [];
        }

        $actions = [];
        foreach (array_keys($this->aggregateHospitalScope->forCurrentUser()) as $hospitalId) {
            if (!\in_array($hospitalId, $hospitalIds, true)) {
                continue;
            }

            $actions[$hospitalId] = [
                new MyHospitalAction(
                    key: self::KEY,
                    labelKey: 'my_hospitals.action.encounter_data',
                    translationDomain: 'encounter',
                    icon: 'tabler:stethoscope',
                    route: 'app_stats_encounter_analytics',
                    routeParameters: ['scope' => 'hospital', 'hospital' => $hospitalId],
                ),
            ];
        }

        return $actions;
    }
}
