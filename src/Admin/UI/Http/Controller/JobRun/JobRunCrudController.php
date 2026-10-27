<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\JobRun;

use App\Admin\Application\Service\JobRun\JobRunCatalog;
use App\Admin\Application\Service\JobRun\JobRunFormatter;
use App\Admin\UI\Http\Controller\DashboardController;
use App\Admin\UI\Http\Controller\Import\ImportCrudController;
use App\Shared\Infrastructure\Monitoring\JobRun\Entity\JobRun;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\ActionGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NullFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Read-only list of background job runs (job_run, ADR 018): find slow, failed or repeated runs without SQL.
 *
 * @extends AbstractCrudController<JobRun>
 */
#[IsGranted('ROLE_ADMIN')]
final class JobRunCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly JobRunFormatter $formatter,
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return JobRun::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular($this->t('entity.singular'))
            ->setEntityLabelInPlural($this->t('entity.plural'))
            ->setPageTitle(Crud::PAGE_INDEX, $this->t('entity.plural'))
            ->setPageTitle(Crud::PAGE_DETAIL, $this->t('entity.singular'))
            ->setDefaultSort(['startedAt' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setSearchFields(['id', 'importRunId', 'errorClass']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $shortcuts = ActionGroup::new('jobRunShortcuts', $this->t('action.shortcuts'), 'fas fa-bolt')
            ->createAsGlobalActionGroup()
            ->addAction(Action::new('jobRunFailed', $this->t('action.failed'), 'fas fa-circle-xmark')
                ->linkToUrl(fn (): string => $this->indexUrl(['status' => ['comparison' => ComparisonType::EQ, 'value' => ['failed']]])))
            ->addAction(Action::new('jobRunThresholds', $this->t('action.thresholds_exceeded'), 'fas fa-gauge-high')
                ->linkToUrl(fn (): string => $this->indexUrl(['thresholdsExceeded' => NullFilter::CHOICE_VALUE_NOT_NULL])))
            ->addAction(Action::new('jobRunLast24h', $this->t('action.last_24h'), 'fas fa-calendar-day')
                ->linkToUrl(fn (): string => $this->indexUrl(['startedAt' => [
                    'comparison' => ComparisonType::GTE,
                    'value' => $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s'),
                ]])));

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $shortcuts)
            ->add(Crud::PAGE_DETAIL, Action::new('jobRunAttempts', $this->t('action.attempts'), 'fas fa-rotate')
                ->displayIf(static fn (JobRun $run): bool => null !== $run->getImportRunId())
                ->linkToUrl(fn (JobRun $run): string => $this->indexUrl(['importRunId' => [
                    'comparison' => ComparisonType::EQ,
                    'value' => (string) $run->getImportRunId(),
                ]])))
            ->add(Crud::PAGE_DETAIL, Action::new('jobRunImport', $this->t('action.runs_of_import'), 'fas fa-database')
                ->displayIf(static fn (JobRun $run): bool => null !== $run->getImportId())
                ->linkToUrl(fn (JobRun $run): string => $this->indexUrl(['importId' => [
                    'comparison' => ComparisonType::EQ,
                    'value' => $run->getImportId(),
                ]])))
            ->add(Crud::PAGE_DETAIL, Action::new('jobRunJob', $this->t('action.runs_of_job'), 'fas fa-list')
                ->linkToUrl(fn (JobRun $run): string => $this->indexUrl(['job' => [
                    'comparison' => ComparisonType::EQ,
                    'value' => [$run->getJob()],
                ]])));
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        $filters
            ->add(ChoiceFilter::new('job', $this->t('field.job'))
                ->setTranslatableChoices(JobRunCatalog::jobChoices())
                ->canSelectMultiple())
            ->add(ChoiceFilter::new('status', $this->t('field.status'))
                ->setTranslatableChoices(JobRunCatalog::statusChoices())
                ->canSelectMultiple())
            ->add(ChoiceFilter::new('triggeredBy', $this->t('field.trigger'))
                ->setTranslatableChoices(JobRunCatalog::triggerChoices())
                ->canSelectMultiple());

        // EasyAdmin refuses a choice filter without choices, e.g. before the first hospital exists.
        $hospitals = $this->formatter->hospitalChoices();
        if ([] !== $hospitals) {
            $filters->add(ChoiceFilter::new('hospitalId', $this->t('field.hospital'))->setChoices($hospitals));
        }

        $filters
            ->add(DateTimeFilter::new('startedAt', $this->t('field.started_at')))
            ->add(NullFilter::new('thresholdsExceeded', $this->t('field.thresholds_exceeded'))
                ->setChoiceLabels($this->t('filter.thresholds.none'), $this->t('filter.thresholds.some')))
            ->add(NumericFilter::new('importId', $this->t('field.import')))
            ->add(TextFilter::new('importRunId', $this->t('field.import_run')));

        return $filters;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset($this->t('fieldset.run'));
        yield DateTimeField::new('startedAt', $this->t('field.started_at'))
            ->setFormat('dd.MM.yy HH:mm:ss')
            ->addCssClass('text-nowrap');
        yield ChoiceField::new('job', $this->t('field.job'))
            ->setTranslatableChoices(static fn (?JobRun $run): array => JobRunCatalog::jobChoices($run?->getJob()));
        yield ChoiceField::new('triggeredBy', $this->t('field.trigger'))
            ->setTranslatableChoices(static fn (?JobRun $run): array => JobRunCatalog::triggerChoices($run?->getTriggeredBy()))
            ->renderAsBadges(JobRunCatalog::TRIGGER_BADGES);
        yield ChoiceField::new('status', $this->t('field.status'))
            ->setTranslatableChoices(static fn (?JobRun $run): array => JobRunCatalog::statusChoices($run?->getStatus()))
            ->renderAsBadges(JobRunCatalog::STATUSES);
        yield IntegerField::new('attempt', $this->t('field.attempt'))
            ->onlyOnDetail();
        yield TextField::new('id', $this->t('field.id'))
            ->onlyOnDetail();

        yield FormField::addFieldset($this->t('fieldset.scope'));
        yield IntegerField::new('hospitalId', $this->t('field.hospital'))
            ->formatValue(fn (?int $id): string => $this->formatter->hospital($id))
            ->onlyOnDetail();
        yield IntegerField::new('importId', $this->t('field.import'))
            ->setTemplatePath('@Admin/crud/field/job_run_import.html.twig')
            ->formatValue(fn (?int $id): ?string => null === $id ? null : $this->importDetailUrl($id))
            ->onlyOnDetail();
        yield TextField::new('importRunId', $this->t('field.import_run'))
            ->onlyOnDetail();

        yield FormField::addFieldset($this->t('fieldset.timing'));
        yield DateTimeField::new('finishedAt', $this->t('field.finished_at'))
            ->setFormat('dd.MM.yy HH:mm:ss')
            ->onlyOnDetail();
        yield IntegerField::new('durationMs', $this->t('field.duration'))
            ->formatValue(static fn (?int $ms): string => JobRunFormatter::duration($ms))
            ->addCssClass('text-nowrap');
        yield IntegerField::new('peakMemoryBytes', $this->t('field.peak_memory'))
            ->formatValue(static fn (?int $bytes): string => JobRunFormatter::memory($bytes))
            ->addCssClass('text-nowrap');
        // Virtual fields: a generic Field on a mapped property is replaced by its guessed type, which drops the template.
        yield Field::new('thresholdBadges', $this->t('field.thresholds_exceeded'))
            ->setVirtual(true)
            ->setTemplatePath('@Admin/crud/field/job_run_thresholds.html.twig')
            ->formatValue(static fn (mixed $_, JobRun $run): array => array_map(
                JobRunCatalog::thresholdLabel(...),
                $run->getThresholdsExceededList(),
            ));

        if ($this->showsError($pageName)) {
            yield FormField::addFieldset($this->t('fieldset.error'))
                ->onlyOnDetail();
            yield TextField::new('errorClass', $this->t('field.error_class'))
                ->onlyOnDetail();
            yield TextareaField::new('errorMessage', $this->t('field.error_message'))
                ->onlyOnDetail()
                ->renderAsHtml(false);
        }

        yield FormField::addFieldset($this->t('fieldset.metrics'))
            ->onlyOnDetail();
        yield Field::new('metricsTable', $this->t('field.metrics'))
            ->setVirtual(true)
            ->onlyOnDetail()
            ->setTemplatePath('@Admin/crud/field/job_run_metrics.html.twig')
            ->formatValue(fn (mixed $_, JobRun $run): array => $this->formatter->metricsRows($run));
    }

    /**
     * The error fieldset only appears on the detail page of a run that has an error.
     */
    private function showsError(string $pageName): bool
    {
        if (Crud::PAGE_DETAIL !== $pageName) {
            return false;
        }
        $run = $this->getContext()?->getEntity()->getInstance();

        return !$run instanceof JobRun || null !== $run->getErrorClass() || null !== $run->getErrorMessage();
    }

    private function t(string $key): TranslatableMessage
    {
        return new TranslatableMessage('admin.job_run.'.$key, domain: 'admin');
    }

    private function importDetailUrl(int $importId): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(ImportCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($importId)
            ->generateUrl();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function indexUrl(array $filters): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setDashboard(DashboardController::class)
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->set(EA::FILTERS, $filters)
            ->generateUrl();
    }
}
