<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Statistics\AnalysisExplorer\Application\Comparison\ScopeHospitalIdsReaderInterface;
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\AnalysisExplorer\UI\Form\ExplorerEditFormType;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\Translation\TranslatorInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Switching the reference comparison (#1346) on in the edit form: the column axis is removed and the field disabled,
 * the share within each group is preselected for the case count only, and a later explicit choice survives saving
 * and reopening. The comparison is opt-in: its switch is off without references, and switching it off removes them. Five hospitals with allocations make up the public scope; a sixth has no allocations.
 */
#[ResetDatabase]
final class ReferenceComparisonEditFormTest extends KernelTestCase
{
    use Factories;

    /** @var list<int> */
    private array $hospitals = [];

    private int $withoutAllocations;

    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
            $this->hospitals[] = (int) HospitalFactory::createOne(['name' => 'Hospital '.$letter])->getId();
        }
        $this->withoutAllocations = (int) HospitalFactory::createOne(['name' => 'Hospital F'])->getId();
        $this->admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        self::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($this->admin, 'main', $this->admin->getRoles()));

        $connection = self::getContainer()->get(Connection::class);
        foreach ($this->hospitals as $index => $hospitalId) {
            $connection->insert('allocation_stats_projection', [
                'id' => $index + 1, 'import_id' => 1, 'hospital_id' => $hospitalId, 'state_id' => 1, 'dispatch_area_id' => 1,
                'speciality_id' => 1, 'department_id' => 1, 'assignment_id' => 1, 'indication_normalized_id' => 101,
                'created_at' => '2024-03-10 08:00:00', 'arrival_at' => '2024-03-10 08:20:00',
                'created_year' => 2024, 'created_quarter' => 1, 'created_month' => 3, 'created_week' => 10, 'created_day' => 10,
                'created_weekday' => 7, 'created_hour' => 8, 'day_time_bucket_code' => 1, 'shift_bucket_code' => 1,
                'transport_time_minutes' => 20, 'urgency_code' => 1,
            ]);
        }
    }

    public function testTheScopeHoldsTheHospitalsWithAllocations(): void
    {
        $ids = self::getContainer()->get(ScopeHospitalIdsReaderInterface::class)->hospitalIds(StatisticsScopeCriteria::public());

        self::assertSame($this->hospitals, $ids);
        self::assertNotContains($this->withoutAllocations, $ids);
    }

    public function testSwitchingOnWithTheCaseCountRemovesTheColumnAndPreselectsTheShare(): void
    {
        $form = $this->form($this->formData(columnDimension: 'urgency'));
        $form->submit($this->submitted('allocation_count', [$this->hospitals[0]], columnDimension: 'urgency'));

        $data = $this->data($form);
        self::assertSame([$this->hospitals[0]], $data->referenceHospitals);
        self::assertNull($data->columnDimension);
        self::assertTrue($data->showPercentOfTotal);
        self::assertTrue($form->get('columnDimension')->getConfig()->getOption('disabled'));
    }

    public function testTheSwitchIsOffWithoutReferencesAndOnWithThem(): void
    {
        self::assertFalse($this->form($this->formData())->get('referenceComparison')->getData());
        $form = $this->form($this->formData(referenceHospitals: [$this->hospitals[0]]));
        self::assertTrue($form->get('referenceComparison')->getData());
        self::assertTrue($form->get('referenceHospitals')->getConfig()->getOption('expanded'), 'The hospitals are checkboxes.');
    }

    public function testTheGroupTitleAndHelpFollowTheNumberOfOwnHospitals(): void
    {
        $form = $this->form($this->formData());
        self::assertSame(['count' => 5, 'max' => 2], $form->get('referenceComparison')->getConfig()->getOption('help_translation_parameters'), 'Five hospitals in scope leave room for two references.');

        $translator = self::getContainer()->get(TranslatorInterface::class);
        $title = 'stats.analysis_explorer.edit.section.own_hospitals_comparison';
        self::assertSame('Meine Klinik im Vergleich', $translator->trans($title, ['count' => 1], 'statistics', 'de'));
        self::assertSame('Meine Kliniken im Vergleich', $translator->trans($title, ['count' => 3], 'statistics', 'de'));
        self::assertSame(
            'Vergleicht Ihre Klinik mit den anderen Kliniken im Scope.',
            $translator->trans('stats.analysis_explorer.edit.reference_hospitals_help', ['count' => 1, 'max' => 1], 'statistics', 'de'),
        );
    }

    public function testSwitchingOffRemovesTheReferencesAndTheShare(): void
    {
        $form = $this->form($this->formData(referenceHospitals: [$this->hospitals[0]], showPercentOfTotal: true));
        // The hospital stays ticked in the hidden panel; the switch decides.
        $submitted = $this->submitted('allocation_count', [$this->hospitals[0]], comparison: false);
        $submitted['showPercentOfTotal'] = '1';
        $form->submit($submitted);

        $data = $this->data($form);
        self::assertSame([], $data->referenceHospitals);
        self::assertFalse($data->showPercentOfTotal, 'The share the comparison preselected goes with it.');
        self::assertFalse($form->get('columnDimension')->getConfig()->getOption('disabled'));
    }

    public function testASubmissionWithoutTheSwitchKeepsTheReferences(): void
    {
        $form = $this->form($this->formData(referenceHospitals: [$this->hospitals[0]]));
        $submitted = $this->submitted('allocation_count', [$this->hospitals[0]]);
        unset($submitted['referenceComparison']);
        $form->submit($submitted);

        self::assertSame([$this->hospitals[0]], $this->data($form)->referenceHospitals);
    }

    public function testSwitchingOnWithoutAHospitalChangesNothing(): void
    {
        $form = $this->form($this->formData(columnDimension: 'urgency'));
        $form->submit($this->submitted('allocation_count', [], columnDimension: 'urgency', comparison: true));

        $data = $this->data($form);
        self::assertSame([], $data->referenceHospitals);
        self::assertSame('urgency', $data->columnDimension);
        self::assertFalse($data->showPercentOfTotal);
    }

    public function testSwitchingOnWithARateKeepsTheDisplay(): void
    {
        $form = $this->form($this->formData(metric: 'resus_rate'));
        $form->submit($this->submitted('resus_rate', [$this->hospitals[0]]));

        $data = $this->data($form);
        self::assertSame([$this->hospitals[0]], $data->referenceHospitals);
        self::assertFalse($data->showPercentOfTotal);
    }

    public function testAnExplicitChoiceSurvivesSavingAndReopening(): void
    {
        // The comparison is already on; the user unticks the share.
        $form = $this->form($this->formData(referenceHospitals: [$this->hospitals[0]], showPercentOfTotal: true));
        $form->submit($this->submitted('allocation_count', [$this->hospitals[0]]));
        $data = $this->data($form);
        self::assertFalse($data->showPercentOfTotal);

        $mapper = self::getContainer()->get(ExplorerConfigMapper::class);
        $config = $mapper->toViewConfig($data, self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault($this->filter()), $this->admin);
        self::assertSame([$this->hospitals[0]], $config->referenceHospitalIds);
        self::assertNotContains(AnalysisMetricKey::PercentOfSeries, $config->metricKeys);

        $reopened = $mapper->toFormData($mapper->viewConfigFromState($mapper->toStateArray($config), $this->admin));
        self::assertSame([$this->hospitals[0]], $reopened->referenceHospitals);
        self::assertFalse($reopened->showPercentOfTotal);

        $form = $this->form($reopened);
        $form->submit($this->submitted('allocation_count', [$this->hospitals[0]]));
        self::assertFalse($this->data($form)->showPercentOfTotal, 'Submitting again does not switch the share back on.');
    }

    /**
     * @return FormInterface<ExplorerEditFormData>
     */
    private function form(ExplorerEditFormData $data): FormInterface
    {
        return self::getContainer()->get(FormFactoryInterface::class)->create(ExplorerEditFormType::class, $data, [
            'locale' => 'en',
            'csrf_protection' => false,
        ]);
    }

    /**
     * @param FormInterface<ExplorerEditFormData> $form
     */
    private function data(FormInterface $form): ExplorerEditFormData
    {
        self::assertTrue($form->isSynchronized(), (string) $form->getTransformationFailure()?->getMessage());
        $data = $form->getData();
        self::assertInstanceOf(ExplorerEditFormData::class, $data);

        return $data;
    }

    /**
     * @param list<int> $referenceHospitals
     */
    private function formData(
        string $metric = 'allocation_count',
        ?string $columnDimension = null,
        array $referenceHospitals = [],
        bool $showPercentOfTotal = false,
    ): ExplorerEditFormData {
        return new ExplorerEditFormData(
            scopePeriod: new StatisticsScopePeriodFormData('public', null, 'all_time'),
            dataSource: 'allocations',
            rowDimension: 'indication',
            rowGrain: 'total',
            columnDimension: $columnDimension,
            columnGrain: null === $columnDimension ? null : 'total',
            metric: $metric,
            showPercentOfTotal: $showPercentOfTotal,
            chartType: 'bar',
            referenceHospitals: $referenceHospitals,
        );
    }

    /**
     * The share checkbox is left out: an unticked checkbox is not submitted. The comparison switch follows the
     * references unless given; the Live Component sends an unticked switch as null.
     *
     * @param list<int> $referenceHospitals
     *
     * @return array<string, mixed>
     */
    private function submitted(string $metric, array $referenceHospitals, string $columnDimension = '', ?bool $comparison = null): array
    {
        return [
            'referenceComparison' => ($comparison ?? [] !== $referenceHospitals) ? '1' : null,
            'scopePeriod' => ['scopeGroup' => 'public', 'period' => 'all_time'],
            'dataSource' => 'allocations',
            'rowDimension' => 'indication',
            'rowGrain' => 'total',
            'columnDimension' => $columnDimension,
            'columnGrain' => '' === $columnDimension ? '' : 'total',
            'metric' => $metric,
            'chartType' => 'bar',
            'tableLayout' => 'flat',
            'chartRowLimit' => 'all',
            'referenceHospitals' => array_map(strval(...), $referenceHospitals),
        ];
    }

    private function filter(): StatisticsFilter
    {
        return new StatisticsFilter(
            scope: StatisticsFilterScope::Public,
            hospitalId: null,
            cohortType: null,
            period: StatisticsFilterPeriod::AllTime,
        );
    }
}
