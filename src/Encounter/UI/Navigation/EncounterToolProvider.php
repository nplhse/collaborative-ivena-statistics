<?php

declare(strict_types=1);

namespace App\Encounter\UI\Navigation;

use App\Encounter\UI\Http\Controller\Review\ListReviewHospitalsController;
use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use App\Shared\Application\Navigation\Tool\ToolProviderInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Lists the encounter match review, the match validation and the data preparation tool in the Tools
 * section (ADR 031). All three belong to the encounter pilot and need ROLE_ENCOUNTER_BETA, like their
 * controllers; the per-hospital REVIEW grant is checked on the pages (the hospital list shows only the
 * hospitals the user may review), so the navigation needs no database query. The plausibility table of the
 * matcher (ADR 033) is listed only for ROLE_ENCOUNTER_CATALOG_REVIEWER as well.
 *
 * @psalm-suppress UnusedClass Tagged via ToolProviderInterface.
 */
final readonly class EncounterToolProvider implements ToolProviderInterface
{
    public const string REVIEW = 'encounter_review';
    public const string VALIDATION = 'encounter_validation';
    public const string DATA_PREP = 'encounter_data_prep';
    public const string PLAUSIBILITY = 'encounter_plausibility';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[\Override]
    public function tools(): array
    {
        if (!$this->authorizationChecker->isGranted('ROLE_ENCOUNTER_BETA')) {
            return [];
        }

        $tools = [
            new Tool(
                key: self::REVIEW,
                titleKey: 'tools.encounter_review.title',
                descriptionKey: 'tools.encounter_review.description',
                translationDomain: 'encounter',
                route: 'app_tools_encounter_review',
                icon: 'tabler:arrows-exchange',
                category: ToolCategory::Matching,
                beta: true,
            ),
            new Tool(
                key: self::VALIDATION,
                titleKey: 'tools.encounter_validation.title',
                descriptionKey: 'tools.encounter_validation.description',
                translationDomain: 'encounter',
                route: 'app_tools_encounter_review',
                icon: 'tabler:clipboard-check',
                category: ToolCategory::Matching,
                beta: true,
                routeParameters: ['mode' => ListReviewHospitalsController::MODE_VALIDATION],
            ),
            new Tool(
                key: self::DATA_PREP,
                titleKey: 'tools.encounter_data_prep.title',
                descriptionKey: 'tools.encounter_data_prep.description',
                translationDomain: 'encounter',
                route: 'app_tools_data_prep',
                icon: 'tabler:file-export',
                category: ToolCategory::DataPreparation,
                beta: true,
            ),
        ];

        // The plausibility table (ADR 033) needs the catalog reviewer role on top of the beta role; ROLE_ADMIN inherits both.
        if ($this->authorizationChecker->isGranted('ROLE_ENCOUNTER_CATALOG_REVIEWER')) {
            $tools[] = new Tool(
                key: self::PLAUSIBILITY,
                titleKey: 'tools.encounter_plausibility.title',
                descriptionKey: 'tools.encounter_plausibility.description',
                translationDomain: 'encounter',
                route: 'app_tools_encounter_plausibility',
                icon: 'tabler:table',
                category: ToolCategory::Matching,
                beta: true,
            );
        }

        return $tools;
    }
}
