<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\IndicationRaw;

use App\Allocation\Domain\Entity\IndicationRaw;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<IndicationRaw>
 */
#[IsGranted('ROLE_ADMIN')]
final class IndicationRawCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return IndicationRaw::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.indication_raw.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.indication_raw.entity.plural', domain: 'admin'))
            ->setSearchFields(['id', 'name', 'code'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->disable(Action::NEW, Action::EDIT);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.overview', domain: 'admin'));
        yield IntegerField::new('code', new TranslatableMessage('label.indication.code', domain: 'messages'));
        yield TextField::new('name', new TranslatableMessage('label.name', domain: 'messages'));
        yield AssociationField::new('normalized', new TranslatableMessage('admin.indication_raw.field.normalized', domain: 'admin'));
        yield TextField::new('reviewStatus', new TranslatableMessage('label.indication.review_status', domain: 'messages'))
            ->hideOnForm();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.review', domain: 'admin'));
        yield TextField::new('hash', new TranslatableMessage('admin.indication_raw.field.hash', domain: 'admin'))
            ->onlyOnDetail();
        yield AssociationField::new('target', new TranslatableMessage('admin.indication_raw.field.target', domain: 'admin'))
            ->onlyOnDetail();
        yield TextareaField::new('reviewComment', new TranslatableMessage('label.indication.review_comment', domain: 'messages'))
            ->onlyOnDetail();
        yield DateTimeField::new('reviewedAt', new TranslatableMessage('label.indication.reviewed_at', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('reviewedBy', new TranslatableMessage('label.indication.reviewed_by', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('firstMatchedBy', new TranslatableMessage('label.indication.first_matched_by', domain: 'messages'))
            ->onlyOnDetail();
        yield DateTimeField::new('firstMatchedAt', new TranslatableMessage('label.indication.first_matched_at', domain: 'messages'))
            ->onlyOnDetail();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield AssociationField::new('createdBy', new TranslatableMessage('label.created_by', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', new TranslatableMessage('label.updated_by', domain: 'messages'))
            ->onlyOnDetail();
    }
}
