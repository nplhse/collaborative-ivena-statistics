<?php

declare(strict_types=1);

namespace App\Encounter\UI\CaseFile;

use App\Allocation\Application\CaseFile\AllocationCaseSection;
use App\Allocation\Application\CaseFile\AllocationCaseSectionProviderInterface;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\CaseCard\HospitalCourseFactory;
use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogEntry;
use App\Encounter\Application\Contract\HospitalCourseReaderInterface;
use App\Encounter\Application\Display\RecordSectionRegistry;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Assignment\EncounterAllocationAssignmentsInterface;
use App\Encounter\Domain\Entity\EdVisit;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\UI\Glossary\MtsNames;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Card "Hospital course" on the allocation case file (#1055), contributed through the Allocation
 * extension point so Allocation does not depend on Encounter. Only for users with VIEW_CASES on the
 * allocation's hospital (ADR 028; administrators hold it implicitly). Without an effective link the
 * card says so; proposals are not shown here (match review, #1054). With a link, the card also shows the
 * record sections of other encounter schemas for the linked encounter (ADR 037).
 *
 * @psalm-suppress UnusedClass Tagged via AllocationCaseSectionProviderInterface.
 */
final readonly class HospitalCourseCaseSectionProvider implements AllocationCaseSectionProviderInterface
{
    public const string KEY = 'encounter_hospital_course';

    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private HospitalCourseReaderInterface $reader,
        private HospitalCourseFactory $factory,
        private MtsCatalog $mtsCatalog,
        private MtsNames $mtsNames,
        private RecordSectionRegistry $recordSections,
        private EncounterAllocationAssignmentsInterface $assignments,
    ) {
    }

    #[\Override]
    public function sectionsFor(Allocation $allocation): array
    {
        $hospital = $allocation->getHospital();
        $allocationId = $allocation->getId();
        if (!$hospital instanceof Hospital || null === $allocationId
            || !$this->authorizationChecker->isGranted(EncounterAttribute::VIEW_CASES, $hospital)) {
            return [];
        }

        // The encounter of the allocation is its effective assignment (#1300); records of every schema hang on it,
        // the ED visit is one of them.
        $encounterId = $this->assignments->encounterOfAllocation($allocationId);
        if (null === $encounterId) {
            return [new AllocationCaseSection(self::KEY, '@Encounter/case_card/hospital_course.html.twig', ['course' => null, 'record_sections' => []])];
        }

        $visit = $this->reader->visitOfEncounter($encounterId);
        $link = $visit instanceof EdVisit ? $this->reader->effectiveLinkOfVisit($visit) : null;
        if (!$visit instanceof EdVisit || !$link instanceof EncounterMatch) {
            return [new AllocationCaseSection(self::KEY, '@Encounter/case_card/hospital_course.html.twig', [
                'course' => null,
                'record_sections' => $this->recordSections->forEncounter($encounterId),
                // Without ED visit the ED match review cannot change the assignment; a reviewer can remove it here.
                'can_unassign' => !$visit instanceof EdVisit && $this->authorizationChecker->isGranted(EncounterAttribute::REVIEW, $hospital),
                'allocation_public_id' => $allocation->getPublicId(),
                'allocation_id' => $allocationId,
            ])];
        }

        $triage = $visit->getTriage();

        return [new AllocationCaseSection(self::KEY, '@Encounter/case_card/hospital_course.html.twig', [
            'course' => $this->factory->create($visit, $link),
            'diagram_name' => $this->diagramName($triage->getDiagram()),
            'indicator_name' => $this->indicatorName($triage->getIndicator()),
            'record_sections' => $this->recordSections->forEncounter($encounterId),
        ])];
    }

    private function diagramName(?string $code): ?string
    {
        if (null === $code) {
            return null;
        }

        $entry = $this->mtsCatalog->diagram($code);

        return $entry instanceof MtsCatalogEntry ? $this->mtsNames->diagram($entry)->name : $code;
    }

    private function indicatorName(?string $code): ?string
    {
        if (null === $code) {
            return null;
        }

        $entry = $this->mtsCatalog->indicator($code);

        return $entry instanceof MtsCatalogEntry ? $this->mtsNames->indicator($entry)->name : $code;
    }
}
