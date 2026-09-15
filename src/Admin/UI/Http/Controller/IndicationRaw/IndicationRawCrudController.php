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
            ->setEntityLabelInSingular('Indication Raw')
            ->setEntityLabelInPlural('Indication Raw')
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
        yield IntegerField::new('code', 'Code');
        yield TextField::new('name', 'Name');
        yield AssociationField::new('normalized', 'Normalized');
        yield TextField::new('reviewStatus', 'Review status')
            ->hideOnForm();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.review', domain: 'admin'));
        yield TextField::new('hash', 'Hash')
            ->onlyOnDetail();
        yield AssociationField::new('target', 'Target')
            ->onlyOnDetail();
        yield TextareaField::new('reviewComment', 'Review comment')
            ->onlyOnDetail();
        yield DateTimeField::new('reviewedAt', 'Reviewed at')
            ->onlyOnDetail();
        yield AssociationField::new('reviewedBy', 'Reviewed by')
            ->onlyOnDetail();
        yield AssociationField::new('firstMatchedBy', 'First matched by')
            ->onlyOnDetail();
        yield DateTimeField::new('firstMatchedAt', 'First matched at')
            ->onlyOnDetail();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->onlyOnDetail();
        yield AssociationField::new('createdBy', 'Created by')
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Updated by')
            ->onlyOnDetail();
    }
}
