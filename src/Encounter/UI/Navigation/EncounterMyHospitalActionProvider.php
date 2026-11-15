<?php

declare(strict_types=1);

namespace App\Encounter\UI\Navigation;

use App\Allocation\Application\Hospital\MyHospitals\Action\MyHospitalAction;
use App\Allocation\Application\Hospital\MyHospitals\Action\MyHospitalActionProviderInterface;
use App\Encounter\Application\Overview\AggregateHospitalScope;
use App\Encounter\UI\VisitList\VisitListHospitals;
use App\User\Domain\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Adds "Encounter data" to the My Hospitals cards of the hospitals whose encounter analysis the user may
 * open (#1128): the hospitals of their encounter grants with VIEW_AGGREGATES, for administrators every
 * hospital with stored visits. Replaces the grant list of the removed encounter overview page. The scope
 * is read once per page. "ED visits" (#1178) links to the visit list of the hospitals with VIEW_CASES.
 *
 * @psalm-suppress UnusedClass Tagged via MyHospitalActionProviderInterface.
 */
final readonly class EncounterMyHospitalActionProvider implements MyHospitalActionProviderInterface
{
    public const string KEY = 'encounter_data';

    public const string VISITS_KEY = 'encounter_visits';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private AggregateHospitalScope $aggregateHospitalScope,
        private VisitListHospitals $visitListHospitals,
        private Security $security,
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

        // "ED visits" for the hospitals with VIEW_CASES (#1178); two queries per page.
        $user = $this->security->getUser();
        foreach ($user instanceof User ? $this->visitListHospitals->forUser($user) : [] as $hospital) {
            if (!\in_array($hospital['id'], $hospitalIds, true)) {
                continue;
            }

            $actions[$hospital['id']][] = new MyHospitalAction(
                key: self::VISITS_KEY,
                labelKey: 'my_hospitals.action.ed_visits',
                translationDomain: 'encounter',
                icon: 'tabler:list-details',
                route: 'app_explore_ed_visit_list',
                routeParameters: ['publicId' => $hospital['public_id']],
            );
        }

        return $actions;
    }
}
