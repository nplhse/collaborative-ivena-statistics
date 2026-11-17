<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\CaseFile;

use App\Encounter\Contract\Display\EncounterCourse;
use App\Encounter\Contract\Display\EncounterCourseProviderInterface;
use Cois\Plugin\EdVisit\Application\CaseCard\HospitalCourseFactory;
use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalog;
use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalogEntry;
use Cois\Plugin\EdVisit\Application\Contract\HospitalCourseReaderInterface;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterMatch;
use Cois\Plugin\EdVisit\UI\Glossary\MtsNames;

/**
 * The ED course in the hospital course card (#1055): triage, process times, disposition, timeline and vitals of
 * the encounter's ED visit with its effective link. An ED visit without effective link shows nothing; its
 * assignment is decided in the match review.
 *
 * @psalm-suppress UnusedClass Tagged via EncounterCourseProviderInterface.
 */
final readonly class EdVisitCourseProvider implements EncounterCourseProviderInterface
{
    public function __construct(
        private HospitalCourseReaderInterface $reader,
        private HospitalCourseFactory $factory,
        private MtsCatalog $mtsCatalog,
        private MtsNames $mtsNames,
    ) {
    }

    #[\Override]
    public function courseForEncounter(int $encounterId): ?EncounterCourse
    {
        $visit = $this->reader->visitOfEncounter($encounterId);
        if (!$visit instanceof EdVisit) {
            return null;
        }

        $link = $this->reader->effectiveLinkOfVisit($visit);
        if (!$link instanceof EncounterMatch) {
            return new EncounterCourse();
        }

        $triage = $visit->getTriage();

        return new EncounterCourse('@EdVisit/case_card/hospital_course.html.twig', [
            'course' => $this->factory->create($visit, $link),
            'diagram_name' => $this->diagramName($triage->getDiagram()),
            'indicator_name' => $this->indicatorName($triage->getIndicator()),
        ]);
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
