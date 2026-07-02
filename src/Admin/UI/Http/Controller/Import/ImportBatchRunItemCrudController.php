<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Import;

use App\Import\Domain\Entity\ImportBatchRunItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<ImportBatchRunItem>
 */
#[IsGranted('ROLE_ADMIN')]
final class ImportBatchRunItemCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return ImportBatchRunItem::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Import batch run item')
            ->setEntityLabelInPlural('Import batch run items')
            ->setSearchFields(['importName'])
            ->setDefaultSort(['id' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();
        yield AssociationField::new('run', 'Batch run');
        yield IntegerField::new('importId', 'Import ID');
        yield TextField::new('importName', 'Import name');
        yield ChoiceField::new('status', 'Status')
            ->renderAsBadges();
        yield IntegerField::new('attemptCount', 'Attempts')
            ->onlyOnDetail();
        yield DateTimeField::new('startedAt', 'Started')
            ->onlyOnDetail();
        yield DateTimeField::new('finishedAt', 'Finished')
            ->onlyOnDetail();
        yield TextareaField::new('errorMessage', 'Error')
            ->onlyOnDetail();
    }
}
