<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\IndicationGroup;

use App\Allocation\Domain\Entity\IndicationGroup;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<IndicationGroup>
 */
#[IsGranted('ROLE_ADMIN')]
final class IndicationGroupCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return IndicationGroup::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Indication Group')
            ->setEntityLabelInPlural('Indication Groups')
            ->setSearchFields(['id', 'name', 'category', 'description'])
            ->setDefaultSort(['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_INDEX, $this->createViewStatisticsAction())
            ->add(Crud::PAGE_DETAIL, $this->createViewStatisticsAction())
            ->add(Crud::PAGE_EDIT, $this->createViewStatisticsAction());
    }

    private function createViewStatisticsAction(): Action
    {
        return Action::new('viewStatistics', 'admin.indication_group.action.view_statistics', 'fas fa-chart-line')
            ->linkToRoute(
                'app_stats_indication_group_dashboard',
                static fn (IndicationGroup $group): array => ['groupId' => (int) $group->getId()],
            )
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener noreferrer'])
            ->displayIf(static fn (IndicationGroup $group): bool => null !== $group->getId());
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('name', 'Name');
        yield TextareaField::new('description', 'Description')->hideOnIndex();
        yield TextField::new('category', 'Category');
        yield IntegerField::new('sortOrder', 'Sort order');
        yield AssociationField::new('indications', 'Indications');
        yield DateTimeField::new('createdAt', 'Created')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield AssociationField::new('createdBy', 'Created by')->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Updated by')->onlyOnDetail();
    }
}
