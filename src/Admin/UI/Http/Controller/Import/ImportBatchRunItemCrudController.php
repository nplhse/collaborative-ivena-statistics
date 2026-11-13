<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Import;

use App\Import\Domain\Entity\ImportBatchRunItem;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

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
            ->setEntityLabelInSingular(new TranslatableMessage('admin.import_batch_run_item.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.import_batch_run_item.entity.plural', domain: 'admin'))
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
    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(NumericFilter::new('importId', new TranslatableMessage('label.import_id', domain: 'admin')));
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();
        yield AssociationField::new('run', new TranslatableMessage('admin.import_batch_run.entity.singular', domain: 'admin'));
        yield IntegerField::new('importId', new TranslatableMessage('label.import_id', domain: 'admin'));
        yield TextField::new('importName', new TranslatableMessage('admin.import_batch_run_item.field.import_name', domain: 'admin'));
        yield ChoiceField::new('status', new TranslatableMessage('label.status', domain: 'messages'))
            ->renderAsBadges();
        yield IntegerField::new('attemptCount', new TranslatableMessage('ops.messenger.table.attempts', domain: 'admin'))
            ->onlyOnDetail();
        yield DateTimeField::new('startedAt', new TranslatableMessage('admin.job_run.field.started_at', domain: 'admin'))
            ->onlyOnDetail();
        yield DateTimeField::new('finishedAt', new TranslatableMessage('admin.job_run.field.finished_at', domain: 'admin'))
            ->onlyOnDetail();
        yield TextareaField::new('errorMessage', new TranslatableMessage('admin.import_batch_run_item.field.error', domain: 'admin'))
            ->onlyOnDetail();
    }
}
