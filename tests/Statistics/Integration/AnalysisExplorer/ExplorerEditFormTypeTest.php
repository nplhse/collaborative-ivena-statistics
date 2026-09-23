<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerEditFormType;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\Statistics\UI\Form\PreTranslatedChoiceType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class ExplorerEditFormTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = self::getContainer()->get(FormFactoryInterface::class);
    }

    public function testPreSetDataConfiguresAllocationFilterFields(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->defaultFormData(), [
            'locale' => 'en',
        ]);

        self::assertTrue($form->has('filterUrgency'));
        self::assertTrue($form->has('filterAgeGroup'));
        self::assertFalse($form->get('filterUrgency')->getConfig()->getOption('disabled'));
        self::assertNotEmpty($form->get('filterUrgency')->getConfig()->getOption('choices'));
        self::assertNotEmpty($form->get('filterAgeGroup')->getConfig()->getOption('choices'));
        self::assertSame('messages', $form->get('filterUrgency')->getConfig()->getOption('translation_domain'));
        self::assertFalse($form->get('filterUrgency')->getConfig()->getOption('choice_translation_domain'));
        self::assertSame('statistics', $form->getConfig()->getOption('translation_domain'));
        self::assertFalse($form->get('metric')->getConfig()->getOption('choice_translation_domain'));
        self::assertInstanceOf(
            PreTranslatedChoiceType::class,
            $form->get('metric')->getConfig()->getType()->getInnerType(),
        );
    }

    public function testPreSetDataDisablesFiltersForHospitalDataSource(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->defaultFormData(
            dataSource: 'hospitals',
            rowDimension: 'hospital_tier',
            rowGrain: 'total',
            metric: 'hospital_count',
        ), [
            'locale' => 'en',
        ]);

        self::assertTrue($form->get('filterDepartmentId')->getConfig()->getOption('disabled'));
        self::assertTrue($form->get('filterUrgency')->getConfig()->getOption('disabled'));
    }

    public function testPreSetDataDisablesFilterWhenDimensionUsedAsRowAxis(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->defaultFormData(
            rowDimension: 'urgency',
            rowGrain: 'total',
        ), [
            'locale' => 'en',
        ]);

        self::assertTrue($form->get('filterUrgency')->getConfig()->getOption('disabled'));
        self::assertFalse($form->get('filterGender')->getConfig()->getOption('disabled'));
    }

    public function testPreSetDataDisablesIndicationGroupFilterWhenIndicationIsRowAxis(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->defaultFormData(
            rowDimension: 'indication',
            rowGrain: 'total',
        ), [
            'locale' => 'en',
        ]);

        self::assertTrue($form->get('filterIndicationId')->getConfig()->getOption('disabled'));
        self::assertTrue($form->get('filterIndicationGroupId')->getConfig()->getOption('disabled'));
    }

    public function testHospitalColumnDisablesCompareAndSwitchesPopulationToParticipating(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->hospitalFormData(
            columnDimension: 'hospital_location',
            hospitalPopulation: 'compare',
        ), [
            'locale' => 'en',
        ]);

        $data = $form->getData();
        self::assertInstanceOf(ExplorerEditFormData::class, $data);
        self::assertSame('participating', $data->hospitalPopulation);
        self::assertNull($form->get('hospitalPopulation')->getConfig()->getOption('help'));
        self::assertSame(
            ['disabled' => true],
            $this->hospitalPopulationChoiceAttr($form)('Compare', 'compare', 'compare'),
        );
        self::assertSame(
            [],
            $this->hospitalPopulationChoiceAttr($form)('Participating', 'participating', 'participating'),
        );
    }

    public function testHospitalCompareStaysAvailableWithoutAColumn(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->hospitalFormData(
            hospitalPopulation: 'compare',
        ), [
            'locale' => 'en',
        ]);

        $data = $form->getData();
        self::assertInstanceOf(ExplorerEditFormData::class, $data);
        self::assertSame('compare', $data->hospitalPopulation);
        self::assertSame(
            [],
            $this->hospitalPopulationChoiceAttr($form)('Compare', 'compare', 'compare'),
        );
    }

    public function testHospitalCompareStaysWhenPopulationGroupIsTheColumn(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->hospitalFormData(
            columnDimension: 'hospital_population_group',
            hospitalPopulation: 'compare',
        ), [
            'locale' => 'en',
        ]);

        $data = $form->getData();
        self::assertInstanceOf(ExplorerEditFormData::class, $data);
        self::assertSame('compare', $data->hospitalPopulation);
        self::assertSame(
            [],
            $this->hospitalPopulationChoiceAttr($form)('Compare', 'compare', 'compare'),
        );
    }

    public function testSubmitClearsCompareWhenAColumnIsChosen(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->hospitalFormData(
            hospitalPopulation: 'compare',
        ), [
            'locale' => 'en',
            'csrf_protection' => false,
        ]);

        $form->submit([
            'scopePeriod' => [
                'scopeGroup' => 'public',
                'period' => 'all',
            ],
            'dataSource' => 'hospitals',
            'rowDimension' => 'hospital_tier',
            'rowGrain' => 'total',
            'columnDimension' => 'hospital_location',
            'columnGrain' => 'total',
            'metric' => 'hospital_count',
            'chartType' => 'grouped_bar',
            'tableLayout' => 'matrix',
            'chartRowLimit' => 'all',
            'hospitalPopulation' => 'compare',
        ]);

        self::assertTrue($form->isSynchronized(), (string) $form->getTransformationFailure()?->getMessage());
        $data = $form->getData();
        self::assertInstanceOf(ExplorerEditFormData::class, $data);
        self::assertSame('hospital_location', $data->columnDimension);
        self::assertSame('participating', $data->hospitalPopulation);
    }

    public function testHospitalRowChoicesUseSeparateGeographyAndParticipationGroups(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->hospitalFormData(), [
            'locale' => 'en',
        ]);

        /** @var array<string, array<string, string>> $choices */
        $choices = $form->get('rowDimension')->getConfig()->getOption('choices');
        $values = [];
        foreach ($choices as $groupChoices) {
            foreach ($groupChoices as $value) {
                $values[] = $value;
            }
        }

        self::assertSame(
            ['Hospital profile', 'Geography', 'Participation'],
            array_keys($choices),
        );
        self::assertNotContains('hospital_entity', $values);
        self::assertSame('hospital_size', $choices['Hospital profile']['Size']);
        self::assertSame('hospital_location', $choices['Hospital profile']['Location']);
        self::assertSame('hospital_master_cohort', $choices['Hospital profile']['Hospital cohort']);
    }

    public function testDistributionProfileOffersOnlyTheBoxPlot(): void
    {
        $form = $this->formFactory->create(ExplorerEditFormType::class, $this->hospitalFormData(
            metric: 'beds_distribution',
            chartType: 'box_plot',
        ), [
            'locale' => 'en',
        ]);

        self::assertSame(
            ['box_plot'],
            array_values($form->get('chartType')->getConfig()->getOption('choices')),
        );
    }

    private function defaultFormData(
        string $dataSource = 'allocations',
        string $rowDimension = 'time',
        ?string $rowGrain = 'month',
        string $metric = 'allocation_count',
    ): ExplorerEditFormData {
        return new ExplorerEditFormData(
            scopePeriod: new StatisticsScopePeriodFormData('public', null, 'all'),
            dataSource: $dataSource,
            rowDimension: $rowDimension,
            rowGrain: $rowGrain,
            metric: $metric,
        );
    }

    private function hospitalFormData(
        ?string $columnDimension = null,
        string $hospitalPopulation = 'participating',
        string $metric = 'hospital_count',
        string $chartType = 'bar',
    ): ExplorerEditFormData {
        return new ExplorerEditFormData(
            scopePeriod: new StatisticsScopePeriodFormData('public', null, 'all'),
            dataSource: 'hospitals',
            rowDimension: 'hospital_tier',
            rowGrain: 'total',
            columnDimension: $columnDimension,
            columnGrain: null === $columnDimension ? null : 'total',
            metric: $metric,
            chartType: $chartType,
            hospitalPopulation: $hospitalPopulation,
        );
    }

    /**
     * @param FormInterface<ExplorerEditFormData> $form
     *
     * @return callable(mixed, string, mixed): array<string, bool>
     */
    private function hospitalPopulationChoiceAttr(FormInterface $form): callable
    {
        /** @var callable(mixed, string, mixed): array<string, bool> $choiceAttr */
        $choiceAttr = $form->get('hospitalPopulation')->getConfig()->getOption('choice_attr');

        return $choiceAttr;
    }
}
