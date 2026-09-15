<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Import;

use App\Admin\UI\Http\Controller\ImportReject\ImportRejectCrudController;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
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
            ->setEntityLabelInSingular('Import')
            ->setEntityLabelInPlural('Imports')
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

        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_DETAIL, $rejects)
            ->add(Crud::PAGE_DETAIL, $batchItems);
    }

    #[\Override]
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('hospital', 'Hospital'))
            ->add(ChoiceFilter::new('status', 'Status')->setChoices(ImportStatus::cases()))
            ->add(ChoiceFilter::new('type', 'Type')->setChoices(ImportType::cases()))
            ->add(DateTimeFilter::new('createdAt', 'Created'))
            ->add(NumericFilter::new('rowCount', 'Rows total'))
            ->add(NumericFilter::new('rowsPassed', 'Rows passed'))
            ->add(NumericFilter::new('rowsRejected', 'Rows rejected'));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.overview', domain: 'admin'));
        yield TextField::new('name', 'Name')
            ->setSortable(true);

        yield AssociationField::new('hospital', 'Hospital');

        yield ChoiceField::new('status', 'Status')
            ->setDisabled()
            ->renderAsBadges();
        yield ChoiceField::new('type', 'Type')
            ->setDisabled()
            ->renderAsBadges();
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('dd.MM.yy HH:mm')
            ->hideOnForm();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.row_stats', domain: 'admin'));
        yield IntegerField::new('rowCount', 'Rows total')
            ->onlyOnDetail();
        yield IntegerField::new('rowsPassed', 'Rows passed')
            ->onlyOnDetail();
        yield IntegerField::new('rowsRejected', 'Rows rejected')
            ->onlyOnDetail();
        yield IntegerField::new('rowsDeduplicated', 'Rows deduplicated')
            ->onlyOnDetail();
        yield IntegerField::new('rowsDeduplicatedDiscarded', 'Rows deduplicated (Discarded)')
            ->onlyOnDetail();
        yield IntegerField::new('rowsDeduplicatedReplaced', 'Rows deduplicated (Replaced)')
            ->onlyOnDetail();
        yield IntegerField::new('runCount', 'Run count')
            ->onlyOnDetail();
        yield IntegerField::new('runTime', 'Runtime (ms)')
            ->onlyOnDetail();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.file', domain: 'admin'));
        yield TextField::new('filePath', 'Source file')
            ->onlyOnDetail();
        yield TextField::new('rejectFilePath', 'Reject file')
            ->onlyOnDetail();
        yield TextField::new('fileExtension', 'File extension')
            ->onlyOnDetail();
        yield TextField::new('fileMimeType', 'MIME type')
            ->onlyOnDetail();
        yield IntegerField::new('fileSize', 'File size (bytes)')
            ->onlyOnDetail();
        yield TextField::new('fileChecksum', 'Checksum')
            ->onlyOnDetail();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setFormat('dd.MM.yy HH:mm')
            ->onlyOnDetail();
        yield AssociationField::new('createdBy', 'Created by')
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Updated by')
            ->onlyOnDetail();
    }

    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        $this->importDeletionService->delete($entityInstance);
    }
}
