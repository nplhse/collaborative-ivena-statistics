<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Import;

use App\Admin\UI\Http\Controller\ImportReject\ImportRejectCrudController;
use App\Admin\UI\Http\Controller\JobRun\JobRunCrudController;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportExportPeriodSource;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<Import>
 */
#[IsGranted('ROLE_ADMIN')]
final class ImportCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ImportDeletionService $importDeletionService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly ImportExportPeriodDeriver $exportPeriodDeriver,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Import::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.import.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.import.entity.plural', domain: 'admin'))
            ->setSearchFields(['id', 'name', 'hospital.name'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $rejects = Action::new(
            'importRejects',
            new TranslatableMessage('admin.import.action.rejects', domain: 'admin'),
            'fas fa-ban',
        )
            ->linkToUrl(fn (Import $import): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(ImportRejectCrudController::class)
                ->setAction(Action::INDEX)
                ->set(EA::FILTERS, [
                    'import' => [
                        'comparison' => ComparisonType::EQ,
                        'value' => $import->getId(),
                    ],
                ])
                ->generateUrl());

        $batchItems = Action::new(
            'importBatchItems',
            new TranslatableMessage('admin.import.action.batch_items', domain: 'admin'),
            'fas fa-list-check',
        )
            ->linkToUrl(fn (Import $import): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(ImportBatchRunItemCrudController::class)
                ->setAction(Action::INDEX)
                ->set(EA::FILTERS, [
                    'importId' => [
                        'comparison' => ComparisonType::EQ,
                        'value' => $import->getId(),
                    ],
                ])
                ->generateUrl());

        $jobRuns = Action::new(
            'importJobRuns',
            new TranslatableMessage('admin.import.action.job_runs', domain: 'admin'),
            'fas fa-stopwatch',
        )
            ->linkToUrl(fn (Import $import): string => $this->adminUrlGenerator
                ->unsetAll()
                ->setController(JobRunCrudController::class)
                ->setAction(Action::INDEX)
                ->set(EA::FILTERS, [
                    'importId' => [
                        'comparison' => ComparisonType::EQ,
                        'value' => $import->getId(),
                    ],
                ])
                ->generateUrl());

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_DETAIL, $rejects)
            ->add(Crud::PAGE_DETAIL, $batchItems)
            ->add(Crud::PAGE_DETAIL, $jobRuns);
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('hospital', new TranslatableMessage('admin.hospital.entity.singular', domain: 'admin')))
            ->add(ChoiceFilter::new('status', new TranslatableMessage('label.status', domain: 'messages'))->setChoices(ImportStatus::cases()))
            ->add(ChoiceFilter::new('type', new TranslatableMessage('import.field.type', domain: 'import'))->setChoices(ImportType::cases()))
            ->add(DateTimeFilter::new('createdAt', new TranslatableMessage('label.created', domain: 'messages')))
            ->add(NumericFilter::new('rowCount', new TranslatableMessage('import.field.rowCount', domain: 'import')))
            ->add(NumericFilter::new('rowsPassed', new TranslatableMessage('import.field.rowsPassed', domain: 'import')))
            ->add(NumericFilter::new('rowsRejected', new TranslatableMessage('import.field.rowsRejected', domain: 'import')));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.overview', domain: 'admin'));
        yield TextField::new('name', new TranslatableMessage('label.name', domain: 'messages'))
            ->setSortable(true);

        yield AssociationField::new('hospital', new TranslatableMessage('admin.hospital.entity.singular', domain: 'admin'));

        yield ChoiceField::new('status', new TranslatableMessage('label.status', domain: 'messages'))
            ->setDisabled()
            ->renderAsBadges();
        yield ChoiceField::new('type', new TranslatableMessage('import.field.type', domain: 'import'))
            ->setDisabled()
            ->renderAsBadges();
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yy HH:mm')
            ->hideOnForm();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.row_stats', domain: 'admin'));
        yield IntegerField::new('rowCount', new TranslatableMessage('import.field.rowCount', domain: 'import'))
            ->onlyOnDetail();
        yield IntegerField::new('rowsPassed', new TranslatableMessage('import.field.rowsPassed', domain: 'import'))
            ->onlyOnDetail();
        yield IntegerField::new('rowsRejected', new TranslatableMessage('import.field.rowsRejected', domain: 'import'))
            ->onlyOnDetail();
        yield IntegerField::new('rowsDeduplicated', new TranslatableMessage('import.field.rowsDeduplicated', domain: 'import'))
            ->onlyOnDetail();
        yield IntegerField::new('rowsDeduplicatedDiscarded', new TranslatableMessage('import.field.rowsDeduplicatedDiscarded', domain: 'import'))
            ->onlyOnDetail();
        yield IntegerField::new('rowsDeduplicatedReplaced', new TranslatableMessage('import.field.rowsDeduplicatedReplaced', domain: 'import'))
            ->onlyOnDetail();
        yield IntegerField::new('runCount', new TranslatableMessage('import.field.runCount', domain: 'import'))
            ->onlyOnDetail();
        yield IntegerField::new('runTime', new TranslatableMessage('admin.import.field.runtime_ms', domain: 'admin'))
            ->onlyOnDetail();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.export_period', domain: 'admin'))
            ->setHelp(new TranslatableMessage('admin.import.export_period.help', domain: 'admin'));
        yield DateTimeField::new('exportStartsAt', new TranslatableMessage('admin.import.field.export_starts_at', domain: 'admin'))
            ->setFormat('dd.MM.yy HH:mm')
            ->hideOnIndex();
        yield DateTimeField::new('exportEndsAt', new TranslatableMessage('admin.import.field.export_ends_at', domain: 'admin'))
            ->setFormat('dd.MM.yy HH:mm')
            ->hideOnIndex();
        yield ChoiceField::new('exportPeriodSource', new TranslatableMessage('admin.import.field.export_period_source', domain: 'admin'))
            ->setChoices(ImportExportPeriodSource::cases())
            ->hideOnIndex();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.file', domain: 'admin'));
        yield TextField::new('filePath', new TranslatableMessage('label.import.section.source_file', domain: 'import'))
            ->onlyOnDetail();
        yield TextField::new('rejectFilePath', new TranslatableMessage('admin.import.field.reject_file', domain: 'admin'))
            ->onlyOnDetail();
        yield TextField::new('fileExtension', new TranslatableMessage('import.field.fileExtension', domain: 'import'))
            ->onlyOnDetail();
        yield TextField::new('fileMimeType', new TranslatableMessage('label.mime_type', domain: 'messages'))
            ->onlyOnDetail();
        yield IntegerField::new('fileSize', new TranslatableMessage('label.file_size', domain: 'messages'))
            ->onlyOnDetail();
        yield TextField::new('fileChecksum', new TranslatableMessage('admin.import.field.checksum', domain: 'admin'))
            ->onlyOnDetail();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->setFormat('dd.MM.yy HH:mm')
            ->onlyOnDetail();
        yield AssociationField::new('createdBy', new TranslatableMessage('label.created_by', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', new TranslatableMessage('label.updated_by', domain: 'messages'))
            ->onlyOnDetail();
    }

    /**
     * An edited export period becomes manual unless the source was explicitly reset to derived.
     * Closure Analytics reads the period as coverage, so the hospital is rebuilt.
     */
    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $changes = $unitOfWork->getEntityChangeSet($entityInstance);
        $periodChanged = isset($changes['exportStartsAt']) || isset($changes['exportEndsAt']);
        if ($periodChanged && !isset($changes['exportPeriodSource'])) {
            $entityInstance->setExportPeriodSource(ImportExportPeriodSource::MANUAL);
        }

        parent::updateEntity($entityManager, $entityInstance);

        $hospitalId = $entityInstance->getHospital()?->getId();
        if (($periodChanged || isset($changes['exportPeriodSource'])) && \is_int($hospitalId)) {
            $this->exportPeriodDeriver->notifyCoverageChanged($hospitalId);
        }
    }

    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->importDeletionService->delete($entityInstance);
    }
}
