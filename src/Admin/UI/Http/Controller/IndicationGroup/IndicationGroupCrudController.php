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
use Symfony\Component\Translation\TranslatableMessage;

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
            ->setEntityLabelInSingular(new TranslatableMessage('admin.indication_group.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.indication_group.entity.plural', domain: 'admin'))
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
        return Action::new('viewStatistics', new TranslatableMessage('admin.indication_group.action.view_statistics', domain: 'admin'), 'fas fa-chart-line')
            ->linkToRoute(
                'app_stats_insights_show',
                static fn (IndicationGroup $group): array => [
                    'dimension' => 'indication-groups',
                    'id' => (int) $group->getId(),
                ],
            )
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener noreferrer'])
            ->displayIf(static fn (IndicationGroup $group): bool => null !== $group->getId());
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('name', new TranslatableMessage('label.name', domain: 'messages'));
        yield TextareaField::new('description', new TranslatableMessage('admin.field.description', domain: 'admin'))->hideOnIndex();
        yield TextField::new('category', new TranslatableMessage('label.category', domain: 'messages'));
        yield IntegerField::new('sortOrder', new TranslatableMessage('label.sort_order', domain: 'messages'));
        yield AssociationField::new('indications', new TranslatableMessage('admin.field.indications', domain: 'admin'));
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
        yield AssociationField::new('createdBy', new TranslatableMessage('label.created_by', domain: 'messages'))->onlyOnDetail();
        yield AssociationField::new('updatedBy', new TranslatableMessage('label.updated_by', domain: 'messages'))->onlyOnDetail();
    }
}
