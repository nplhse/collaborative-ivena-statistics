<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Statistics;

use App\Statistics\Domain\Entity\SavedExplorerView;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<SavedExplorerView>
 */
#[IsGranted('ROLE_ADMIN')]
final class SavedExplorerViewCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return SavedExplorerView::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Saved explorer view')
            ->setEntityLabelInPlural('Saved explorer views')
            ->setSearchFields(['slug', 'title', 'category'])
            ->setDefaultSort(['updatedAt' => 'DESC']);
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
        yield TextField::new('slug', 'Slug');
        yield TextField::new('title', 'Title');
        yield TextField::new('category', 'Category');
        yield BooleanField::new('isSystem', 'System view');
        yield TextareaField::new('description', 'Description')
            ->onlyOnDetail();
        yield TextField::new('configJsonPreview', 'Config JSON')
            ->onlyOnDetail()
            ->setValue('')
            ->formatValue(static fn (mixed $_, SavedExplorerView $view): string => json_encode(
                $view->getConfigJson(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ) ?: '');
        yield DateTimeField::new('createdAt', 'Created')
            ->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Updated')
            ->hideOnForm();
        yield AssociationField::new('createdBy', 'Created by')
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', 'Updated by')
            ->onlyOnDetail();
    }
}
