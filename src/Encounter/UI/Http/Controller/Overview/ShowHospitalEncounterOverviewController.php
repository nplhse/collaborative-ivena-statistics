<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Overview;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Catalog\MtsCatalogEntry;
use App\Encounter\Application\Overview\EncounterOverviewFactory;
use App\Encounter\Application\Overview\EncounterPeriod;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\UI\Glossary\MtsNames;
use App\Encounter\UI\Overview\OverviewHospitalChoices;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Descriptive overview of the encounter data of one hospital (#1055): beta role and VIEW_AGGREGATES
 * on that hospital (any encounter grant plus the hospital permission STATISTICS; administrators all
 * hospitals). Never aggregates across hospitals. Hospital and period are chosen in the filter drawer;
 * the content is split into tabs.
 */
final class ShowHospitalEncounterOverviewController extends AbstractController
{
    public const array TABS = ['overview', 'process', 'link', 'distributions', 'coverage'];

    public function __construct(
        private readonly EncounterOverviewFactory $overviewFactory,
        private readonly MtsCatalog $mtsCatalog,
        private readonly MtsNames $mtsNames,
        private readonly OverviewHospitalChoices $hospitalChoices,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/encounter/hospital/{id}', name: 'app_encounter_hospital_overview', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    #[IsGranted(EncounterAttribute::VIEW_AGGREGATES, subject: 'hospital')]
    public function __invoke(Hospital $hospital, Request $request): Response
    {
        $period = $this->period($request);
        $tab = \in_array($request->query->getString('tab'), self::TABS, true) ? $request->query->getString('tab') : self::TABS[0];
        $overview = $this->overviewFactory->create((int) $hospital->getId(), $period);

        $diagramNames = [];
        foreach ($overview->diagrams as $row) {
            $entry = $this->mtsCatalog->diagram($row->key);
            $diagramNames[$row->key] = $entry instanceof MtsCatalogEntry ? $this->mtsNames->diagram($entry)->name : $row->key;
        }

        return $this->render('@Encounter/overview/hospital.html.twig', [
            'hospital' => $hospital,
            'overview' => $overview,
            'diagram_names' => $diagramNames,
            'tab' => $tab,
            'tabs' => self::TABS,
            'hospital_choices' => $this->hospitalChoices->forCurrentUser(),
            'period_years' => $this->years($overview->allTimeCoverage->firstAnchor, $overview->allTimeCoverage->lastAnchor),
            'period_months' => $this->months($overview->allTimeCoverage->firstAnchor, $overview->allTimeCoverage->lastAnchor),
        ]);
    }

    /**
     * Target of the filter drawer: opens the chosen hospital with the chosen period and tab.
     */
    #[Route('/encounter/hospital', name: 'app_encounter_hospital_overview_select', methods: ['GET'])]
    #[IsGranted('ROLE_ENCOUNTER_BETA')]
    public function select(Request $request): RedirectResponse
    {
        $hospitalId = $request->query->getInt('hospital');
        if (!\array_key_exists($hospitalId, $this->hospitalChoices->forCurrentUser())) {
            throw $this->createAccessDeniedException();
        }

        $tab = $request->query->getString('tab');
        $parameters = ['id' => $hospitalId] + $this->period($request)->queryParameters();
        if (\in_array($tab, self::TABS, true) && self::TABS[0] !== $tab) {
            $parameters['tab'] = $tab;
        }

        return $this->redirectToRoute('app_encounter_hospital_overview', $parameters);
    }

    /**
     * Years with stored visits, newest first.
     *
     * @return list<string>
     */
    private function years(?\DateTimeImmutable $first, ?\DateTimeImmutable $last): array
    {
        if (!$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable) {
            return [];
        }

        return array_map(strval(...), range((int) $last->format('Y'), (int) $first->format('Y')));
    }

    /**
     * Months (first day) from the first to the last stored visit, newest first.
     *
     * @return list<\DateTimeImmutable>
     */
    private function months(?\DateTimeImmutable $first, ?\DateTimeImmutable $last): array
    {
        if (!$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable) {
            return [];
        }

        $months = [];
        $month = new \DateTimeImmutable($last->format('Y-m-01'));
        $end = new \DateTimeImmutable($first->format('Y-m-01'));
        while ($month >= $end) {
            $months[] = $month;
            $month = $month->modify('-1 month');
        }

        return $months;
    }

    private function period(Request $request): EncounterPeriod
    {
        $value = static fn (string $key): ?string => '' === $request->query->getString($key) ? null : $request->query->getString($key);

        return EncounterPeriod::fromPreset($value('period'), $value('year'), $value('month'), $value('from'), $value('to'), $this->clock->now());
    }
}
