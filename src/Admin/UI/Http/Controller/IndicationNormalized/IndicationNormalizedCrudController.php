<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\IndicationNormalized;

use App\Allocation\Domain\Entity\IndicationNormalized;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<IndicationNormalized>
 */
final class IndicationNormalizedCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return IndicationNormalized::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Indication Normalized')
            ->setEntityLabelInPlural('Indication Normalized')
            ->setSearchFields(['id', 'name', 'code'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->onlyOnDetail();
        yield IntegerField::new('code', 'Code');
        yield TextField::new('name', 'Name');
        yield TextField::new('note', 'Note')
            ->onlyOnDetail();
        yield AssociationField::new('children', 'Children')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield AssociationField::new('createdBy', 'Created by')
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Updated by')
            ->onlyOnDetail();
    }
}
