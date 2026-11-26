<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Speciality;

use App\Allocation\Domain\Entity\Speciality;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<Speciality>
 */
#[IsGranted('ROLE_ADMIN')]
final class SpecialityCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Speciality::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.speciality.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.speciality.entity.plural', domain: 'admin'))
            ->setSearchFields(['id', 'name'])
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
        yield TextField::new('name', new TranslatableMessage('label.name', domain: 'messages'));
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield AssociationField::new('createdBy', new TranslatableMessage('label.created_by', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', new TranslatableMessage('label.updated_by', domain: 'messages'))
            ->onlyOnDetail();
    }
}
