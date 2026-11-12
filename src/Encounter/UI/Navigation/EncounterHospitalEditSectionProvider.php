<?php

declare(strict_types=1);

namespace App\Encounter\UI\Navigation;

use App\Allocation\Application\Hospital\EditSection\HospitalEditSection;
use App\Allocation\Application\Hospital\EditSection\HospitalEditSectionLink;
use App\Allocation\Application\Hospital\EditSection\HospitalEditSectionProviderInterface;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Security\EncounterAttribute;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Links the encounter grant management from the hospital edit pages (ADR 027) through the
 * Allocation extension point, so Allocation does not depend on Encounter.
 *
 * @psalm-suppress UnusedClass Tagged via HospitalEditSectionProviderInterface.
 */
final readonly class EncounterHospitalEditSectionProvider implements HospitalEditSectionProviderInterface
{
    public const string SECTION_KEY = 'encounter';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[\Override]
    public function sectionsFor(Hospital $hospital): array
    {
        $hospitalId = $hospital->getId();
        if (null === $hospitalId || !$this->authorizationChecker->isGranted(EncounterAttribute::MANAGE_GRANTS, $hospital)) {
            return [];
        }

        return [
            new HospitalEditSection(self::SECTION_KEY, 'encounter.hospital_edit.title', 'encounter', [
                new HospitalEditSectionLink('grants', 'encounter.hospital_edit.grants', 'app_encounter_grants', ['id' => $hospitalId]),
                new HospitalEditSectionLink('grants_new', 'encounter.hospital_edit.grants_new', 'app_encounter_grants_new', ['id' => $hospitalId]),
            ]),
        ];
    }
}
