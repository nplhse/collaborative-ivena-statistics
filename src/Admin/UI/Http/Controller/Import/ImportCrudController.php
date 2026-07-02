<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Import;

use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<Import>
 */
#[IsGranted('ROLE_ADMIN')]
final class ImportCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ImportDeletionService $importDeletionService,
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
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX);
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
        yield IdField::new('id')
            ->onlyOnDetail();
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
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setFormat('dd.MM.yy HH:mm')
            ->onlyOnDetail();
    }

    #[\Override]
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->importDeletionService->delete($entityInstance);
    }
}
