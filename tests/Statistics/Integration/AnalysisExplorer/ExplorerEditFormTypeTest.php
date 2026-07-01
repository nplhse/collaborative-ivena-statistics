<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerEditFormType;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\Statistics\UI\Form\PreTranslatedChoiceType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

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

        self::assertTrue($form->get('filterDepartmentIds')->getConfig()->getOption('disabled'));
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
}
