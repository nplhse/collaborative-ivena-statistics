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
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\EncounterMatch;
use App\Encounter\UI\Glossary\MtsNames;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Card "Hospital course" on the allocation case file (#1055), contributed through the Allocation
 * extension point so Allocation does not depend on Encounter. Only for users with VIEW_CASES on the
 * allocation's hospital (ADR 027; administrators hold it implicitly). Without an effective link the
 * card says so; proposals are not shown here (match review, #1054).
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

        $link = $this->reader->effectiveLinkOfAllocation($allocationId);
        if (!$link instanceof EncounterMatch) {
            return [new AllocationCaseSection(self::KEY, '@Encounter/case_card/hospital_course.html.twig', ['course' => null])];
        }

        $triage = $link->getEncounter()->getTriage();

        return [new AllocationCaseSection(self::KEY, '@Encounter/case_card/hospital_course.html.twig', [
            'course' => $this->factory->create($link),
            'diagram_name' => $this->diagramName($triage->getDiagram()),
            'indicator_name' => $this->indicatorName($triage->getIndicator()),
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
