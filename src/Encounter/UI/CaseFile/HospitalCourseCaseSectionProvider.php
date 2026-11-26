<?php

declare(strict_types=1);

namespace App\Encounter\UI\CaseFile;

use App\Allocation\Application\CaseFile\AllocationCaseSection;
use App\Allocation\Application\CaseFile\AllocationCaseSectionProviderInterface;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Display\RecordSectionRegistry;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Contract\Assignment\EncounterAllocationAssignmentsInterface;
use App\Encounter\Contract\Display\EncounterCourse;
use App\Encounter\Contract\Display\EncounterCourseProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Card "Hospital course" on the allocation case file (#1055), contributed through the Allocation
 * extension point so Allocation does not depend on Encounter. Only for users with VIEW_CASES on the
 * allocation's hospital (ADR 028; administrators hold it implicitly). Without an effective link the
 * card says so; proposals are not shown here (match review, #1054). With a link, the card shows the course of
 * the schema that decides the assignment (EncounterCourseProviderInterface, the ED visit) and the record
 * sections of every schema for the linked encounter (ADR 037).
 *
 * @psalm-suppress UnusedClass Tagged via AllocationCaseSectionProviderInterface.
 */
final readonly class HospitalCourseCaseSectionProvider implements AllocationCaseSectionProviderInterface
{
    public const string KEY = 'encounter_hospital_course';

    private const string TEMPLATE = '@Encounter/case_card/hospital_course.html.twig';

    /**
     * @param iterable<EncounterCourseProviderInterface> $courses
     */
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        #[AutowireIterator(EncounterCourseProviderInterface::TAG)]
        private iterable $courses,
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
            return [new AllocationCaseSection(self::KEY, self::TEMPLATE, ['course' => null, 'record_sections' => []])];
        }

        $course = $this->course($encounterId);

        return [new AllocationCaseSection(self::KEY, self::TEMPLATE, [
            'course' => null !== $course?->template ? $course : null,
            'record_sections' => $this->recordSections->forEncounter($encounterId),
            // Without a deciding schema's record its review cannot change the assignment; a reviewer can remove it here.
            'can_unassign' => !$course instanceof EncounterCourse && $this->authorizationChecker->isGranted(EncounterAttribute::REVIEW, $hospital),
            'allocation_public_id' => $allocation->getPublicId(),
            'allocation_id' => $allocationId,
        ])];
    }

    private function course(int $encounterId): ?EncounterCourse
    {
        foreach ($this->courses as $provider) {
            $course = $provider->courseForEncounter($encounterId);
            if ($course instanceof EncounterCourse) {
                return $course;
            }
        }

        return null;
    }
}
