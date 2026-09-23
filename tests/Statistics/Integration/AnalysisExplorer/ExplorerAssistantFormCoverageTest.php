<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerAssistantDraft;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerAssistantContextType;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerAssistantFiltersType;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerAssistantFlowType;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerAssistantQuestionsType;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Flow\FormFlowInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ExplorerAssistantFormCoverageTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = self::getContainer()->get(FormFactoryInterface::class);
    }

    public function testContextRestoresScopeAndPeriodWhenTheSubmissionIsEmpty(): void
    {
        $draft = new ExplorerAssistantDraft();
        $draft->scopePeriod = new StatisticsScopePeriodFormData(
            scopeGroup: 'state',
            scopeDetail: '4',
            period: 'quarter',
            periodYear: 2024,
            periodQuarter: 2,
            periodMonth: 5,
        );

        $form = $this->formFactory->create(ExplorerAssistantContextType::class, $draft, [
            'locale' => '',
            'data_class' => ExplorerAssistantDraft::class,
        ]);
        $scopeForm = $form->get('scopePeriod');
        $current = $scopeForm->getData();
        self::assertInstanceOf(StatisticsScopePeriodFormData::class, $current);
        $current->scopeGroup = 'state';
        $current->scopeDetail = '4';
        $current->period = 'quarter';
        $current->periodYear = 2024;
        $current->periodQuarter = 2;
        $current->periodMonth = 5;

        $event = new FormEvent($scopeForm, null);
        $listeners = $scopeForm->getConfig()->getEventDispatcher()->getListeners(FormEvents::PRE_SUBMIT);
        $listeners[0]($event);
        $restored = $event->getData();

        self::assertIsArray($restored);
        self::assertSame('state', $restored['scopeGroup']);
        self::assertSame('4', $restored['scopeDetail']);
        self::assertSame('2024', $restored['periodYear']);
        self::assertSame('2', $restored['periodQuarter']);
        self::assertSame('5', $restored['periodMonth']);

        $scopeForm->setData(null);
        $skipped = new FormEvent($scopeForm, null);
        $listeners[0]($skipped);
        self::assertNull($skipped->getData());
    }

    public function testHospitalFiltersFormStaysEmpty(): void
    {
        $draft = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Hospitals);
        $form = $this->formFactory->create(ExplorerAssistantFiltersType::class, $draft, [
            'draft' => $draft,
        ]);

        self::assertCount(0, $form);
    }

    public function testEmptyFilterSubmissionRestoresEveryVisibleValue(): void
    {
        $draft = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations);
        $draft->row = AnalysisDimensionKey::Urgency;
        $this->fillFilters($draft);

        $form = $this->formFactory->create(ExplorerAssistantFiltersType::class, $draft, [
            'draft' => $draft,
            'data_class' => ExplorerAssistantDraft::class,
        ]);
        $filtersType = $form->getConfig()->getType()->getInnerType();
        self::assertInstanceOf(ExplorerAssistantFiltersType::class, $filtersType);
        $event = new FormEvent($form, []);
        $filtersType->restoreOnEmptySubmission($event);

        self::assertIsArray($event->getData());
        self::assertSame('under_18', $event->getData()['filterAgeGroup']);
        self::assertSame(0, $event->getData()['filterResus']);
        self::assertSame(1, $event->getData()['filterCpr']);
        self::assertSame(0, $event->getData()['filterVentilation']);
        self::assertSame(1, $event->getData()['filterDepartmentId']);

        $sparse = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations);
        $sparse->filterAgeGroup = 'under_18';
        $sparseForm = $this->formFactory->create(ExplorerAssistantFiltersType::class, $sparse, [
            'draft' => $sparse,
            'data_class' => ExplorerAssistantDraft::class,
        ]);
        $sparseType = $sparseForm->getConfig()->getType()->getInnerType();
        self::assertInstanceOf(ExplorerAssistantFiltersType::class, $sparseType);
        $sparseEvent = new FormEvent($sparseForm, null);
        $sparseType->restoreOnEmptySubmission($sparseEvent);
        self::assertSame(['filterAgeGroup' => 'under_18'], $sparseEvent->getData());
    }

    public function testFilterSubmissionWithoutTheFlowDoesNotCopyValues(): void
    {
        $draft = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations);
        $form = $this->formFactory->create(ExplorerAssistantFiltersType::class, null, [
            'draft' => $draft,
        ]);
        $form->submit([]);

        self::assertIsArray($form->getData());
    }

    public function testQuestionsWithoutAGoalStayEmptyAndIgnoreANonDraftSubmission(): void
    {
        $empty = $this->draft(null, AnalysisDataSourceKey::Allocations);
        $form = $this->formFactory->create(ExplorerAssistantQuestionsType::class, null, [
            'draft' => $empty,
            'locale' => 'en',
        ]);

        self::assertCount(0, $form);

        $ready = $this->draft(ExplorerAssistantGoal::Matrix, AnalysisDataSourceKey::Allocations);
        $ready->row = AnalysisDimensionKey::Weekday;
        $ready->column = AnalysisDimensionKey::Time;
        $ready->grain = AnalysisDimensionGrain::Year;
        $ready->columnGrain = AnalysisDimensionGrain::Month;
        $form = $this->formFactory->create(ExplorerAssistantQuestionsType::class, null, [
            'draft' => $ready,
            'locale' => '',
        ]);
        $form->submit([]);

        self::assertIsArray($form->getData());

        $bound = $this->formFactory->create(ExplorerAssistantQuestionsType::class, $ready, [
            'draft' => $ready,
            'locale' => 'en',
            'data_class' => ExplorerAssistantDraft::class,
        ]);
        $questionsType = $bound->getConfig()->getType()->getInnerType();
        self::assertInstanceOf(ExplorerAssistantQuestionsType::class, $questionsType);
        $event = new FormEvent($bound, []);
        $questionsType->restoreOnEmptySubmission($event);
        $restored = $event->getData();
        self::assertIsArray($restored);
        self::assertSame('weekday', $restored['row']);
        self::assertSame('time', $restored['column']);
        self::assertSame('year', $restored['grain']);
        self::assertSame('month', $restored['columnGrain']);
    }

    public function testQuestionsAlignUnknownAxesAndReplaceAnUnsupportedMetric(): void
    {
        $draft = $this->draft(ExplorerAssistantGoal::Matrix, AnalysisDataSourceKey::Hospitals);
        $draft->row = AnalysisDimensionKey::Urgency;
        $draft->column = AnalysisDimensionKey::Weekday;
        $draft->metric = AnalysisMetricKey::PercentOfTotal;

        $form = $this->formFactory->create(ExplorerAssistantQuestionsType::class, $draft, [
            'draft' => $draft,
            'locale' => 'en',
        ]);

        self::assertNull($draft->row);
        self::assertNull($draft->column);
        self::assertNotSame(AnalysisMetricKey::PercentOfTotal, $draft->metric);
        self::assertTrue($form->has('suggestion_hospital_location_hospital_tier'));

        $allocations = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations);
        $allocations->row = AnalysisDimensionKey::Urgency;
        $allocations->metric = AnalysisMetricKey::SumBeds;
        $this->formFactory->create(ExplorerAssistantQuestionsType::class, $allocations, [
            'draft' => $allocations,
            'locale' => 'en',
        ]);
        self::assertSame(AnalysisMetricKey::AllocationCount, $allocations->metric);
    }

    public function testFlowAppliesSuggestionsGrainsFiltersAndJumps(): void
    {
        $toplist = $this->submit($this->draft(ExplorerAssistantGoal::Toplist, AnalysisDataSourceKey::Allocations, 'questions'), [
            'questions' => ['suggestion_indication' => '1'],
        ]);
        self::assertSame(AnalysisDimensionKey::Indication, $toplist->row);

        $hospitalToplist = $this->submit($this->draft(ExplorerAssistantGoal::Toplist, AnalysisDataSourceKey::Hospitals, 'questions'), [
            'questions' => ['suggestion_hospital_tier' => '1'],
        ]);
        self::assertSame(AnalysisDimensionKey::HospitalTier, $hospitalToplist->row);

        $matrix = $this->draft(ExplorerAssistantGoal::Matrix, AnalysisDataSourceKey::Allocations, 'questions');
        $matrix->row = AnalysisDimensionKey::Weekday;
        $matrix->column = AnalysisDimensionKey::Time;
        $matrix = $this->submit($matrix, [
            'questions' => [
                'row' => 'weekday',
                'column' => 'time',
                'columnGrain' => 'quarter',
                'metric' => 'allocation_count',
            ],
            'next' => '1',
        ]);
        self::assertSame(AnalysisDimensionGrain::Quarter, $matrix->columnGrain);

        $timeRow = $this->submit($this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations, 'questions'), [
            'questions' => [
                'row' => 'time',
                'metric' => 'allocation_count',
            ],
            'next' => '1',
        ]);
        self::assertSame(AnalysisDimensionKey::Time, $timeRow->row);
        self::assertSame(AnalysisDimensionGrain::Year, $timeRow->grain);

        $filters = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations, 'filters');
        $filters->row = AnalysisDimensionKey::AgeGroup;
        $filters = $this->submit($filters, [
            'filters' => [
                'filterResus' => '0',
                'filterCpr' => '1',
                'filterVentilation' => '0',
            ],
            'next' => '1',
        ]);
        self::assertNull($filters->filterAgeGroup);
        self::assertFalse($filters->filterResus);
        self::assertTrue($filters->filterCpr);

        $age = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations, 'filters');
        $age->row = AnalysisDimensionKey::Urgency;
        $age = $this->submit($age, [
            'filters' => [
                'filterAgeGroup' => 'under_18',
            ],
            'next' => '1',
        ]);
        self::assertSame('under_18', $age->filterAgeGroup);

        $back = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations, 'filters');
        $this->fillFilters($back);
        $back = $this->submit($back, [
            'previous' => '',
        ]);
        self::assertSame(1, $back->filterDepartmentId);
        self::assertSame('questions', $back->currentStep);

        $source = $this->draft(ExplorerAssistantGoal::TimeSeries, AnalysisDataSourceKey::Hospitals, 'source');
        $source->row = null;
        $source->grain = null;
        $source = $this->submit($source, [
            'source' => ['allocations' => '1'],
        ]);
        self::assertSame(AnalysisDataSourceKey::Allocations, $source->dataSource);
        self::assertSame(AnalysisDimensionKey::Time, $source->row);
        self::assertSame(AnalysisDimensionGrain::Year, $source->grain);

        $summary = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations, 'summary');
        $summary->row = AnalysisDimensionKey::Urgency;
        $summary = $this->submit($summary, [
            'summary' => ['edit_questions' => '1'],
        ]);
        self::assertSame('questions', $summary->currentStep);

        $jump = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations, 'summary');
        $jump->row = AnalysisDimensionKey::Urgency;
        $jump = $this->submit($jump, [
            'jump_goal' => '1',
        ]);
        self::assertSame('goal', $jump->currentStep);

        $refresh = $this->draft(ExplorerAssistantGoal::Distribution, AnalysisDataSourceKey::Allocations, 'questions');
        $refresh->row = AnalysisDimensionKey::Gender;
        $refresh = $this->submit($refresh, [
            'refresh' => '1',
        ], '');
        self::assertSame('questions', $refresh->currentStep);
        self::assertSame(AnalysisDimensionKey::Gender, $refresh->row);
    }

    private function draft(?ExplorerAssistantGoal $goal, AnalysisDataSourceKey $dataSource, string $step = 'goal'): ExplorerAssistantDraft
    {
        $draft = new ExplorerAssistantDraft();
        $draft->goal = $goal;
        $draft->dataSource = $dataSource;
        $draft->currentStep = $step;
        $draft->metric = AnalysisMetricKey::defaultFor($dataSource);

        return $draft;
    }

    private function fillFilters(ExplorerAssistantDraft $draft): void
    {
        $draft->filterDepartmentId = 1;
        $draft->filterSpecialityId = 2;
        $draft->filterUrgency = 1;
        $draft->filterTransportType = 1;
        $draft->filterGender = 2;
        $draft->filterAgeGroup = 'under_18';
        $draft->filterResus = false;
        $draft->filterCpr = true;
        $draft->filterVentilation = false;
        $draft->filterAssignmentId = 3;
        $draft->filterIndicationId = 4;
        $draft->filterSecondaryIndicationId = 5;
        $draft->filterIndicationGroupId = 6;
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private function submit(ExplorerAssistantDraft $draft, array $submitted, string $locale = 'en'): ExplorerAssistantDraft
    {
        $request = Request::create('/statistics/analysis/assistant');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $flow = $this->formFactory->create(ExplorerAssistantFlowType::class, $draft, [
            'locale' => $locale,
            'csrf_protection' => false,
        ]);
        self::assertInstanceOf(FormFlowInterface::class, $flow);
        $flow->submit($submitted);
        if ($flow->isSubmitted() && $flow->isValid()) {
            $flow->getStepForm();
        }
        $data = $flow->getData();
        self::assertInstanceOf(ExplorerAssistantDraft::class, $data);

        return $data;
    }
}
