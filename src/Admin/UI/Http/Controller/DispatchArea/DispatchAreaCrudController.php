<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\DispatchArea;

use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Infrastructure\Query\DispatchAreaShapeStatusQuery;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * @extends AbstractCrudController<DispatchArea>
 */
#[IsGranted('ROLE_ADMIN')]
final class DispatchAreaCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly DispatchAreaShapeStatusQuery $shapeStatus,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return DispatchArea::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('admin.dispatch_area.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.dispatch_area.entity.plural', domain: 'admin'))
            ->setSearchFields(['id', 'name'])
            ->setDefaultSort(['id' => 'ASC']);
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
        yield AssociationField::new('state', new TranslatableMessage('label.state', domain: 'messages'));
        yield AssociationField::new('districts', new TranslatableMessage('admin.dispatch_area.field.districts', domain: 'admin'))
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('choice_label', static fn (District $district): string => sprintf('%s %s', $district->getAgs(), $district->getName()))
            ->setQueryBuilder(function (QueryBuilder $queryBuilder): QueryBuilder {
                $area = $this->getContext()?->getEntity()->getInstance();
                $alias = $queryBuilder->getRootAliases()[0];
                $queryBuilder->orderBy($alias.'.ags', 'ASC');

                if ($area instanceof DispatchArea && null !== $area->getId()) {
                    return $queryBuilder
                        ->andWhere(sprintf('%1$s.dispatchArea IS NULL OR %1$s.dispatchArea = :area', $alias))
                        ->setParameter('area', $area->getId());
                }

                return $queryBuilder->andWhere(sprintf('%s.dispatchArea IS NULL', $alias));
            })
            ->setTemplatePath('@Admin/crud/field/dispatch_area_districts.html.twig')
            ->hideOnIndex();
        yield Field::new('mapShape', new TranslatableMessage('admin.dispatch_area.field.map_shape', domain: 'admin'))
            ->setVirtual(true)
            ->hideOnForm()
            ->formatValue(function (mixed $value, DispatchArea $area): ?object {
                $id = $area->getId();

                return null === $id ? null : $this->shapeStatus->forDispatchArea($id);
            })
            ->setTemplatePath('@Admin/crud/field/dispatch_area_map_shape.html.twig');
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
