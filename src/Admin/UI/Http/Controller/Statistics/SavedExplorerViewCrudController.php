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
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

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
            ->setEntityLabelInSingular(new TranslatableMessage('admin.saved_explorer_view.entity.singular', domain: 'admin'))
            ->setEntityLabelInPlural(new TranslatableMessage('admin.saved_explorer_view.entity.plural', domain: 'admin'))
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
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.identity', domain: 'admin'));
        yield TextField::new('slug', new TranslatableMessage('label.slug', domain: 'messages'));
        yield TextField::new('title', new TranslatableMessage('label.title', domain: 'messages'));
        yield TextField::new('category', new TranslatableMessage('label.category', domain: 'messages'));
        yield BooleanField::new('isSystem', new TranslatableMessage('stats.analysis_explorer.view_type.system', domain: 'statistics'));

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.config', domain: 'admin'));
        yield TextareaField::new('description', new TranslatableMessage('admin.field.description', domain: 'admin'))
            ->onlyOnDetail();
        yield TextField::new('configJsonPreview', new TranslatableMessage('admin.saved_explorer_view.field.config_json', domain: 'admin'))
            ->onlyOnDetail()
            ->setValue('')
            ->formatValue(static function (mixed $_, SavedExplorerView $view): string {
                $encoded = json_encode(
                    $view->getConfigJson(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
                );

                return \is_string($encoded) ? $encoded : '';
            });

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt', new TranslatableMessage('label.created', domain: 'messages'))
            ->onlyOnDetail();
        yield DateTimeField::new('updatedAt', new TranslatableMessage('label.updated', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('createdBy', new TranslatableMessage('label.created_by', domain: 'messages'))
            ->onlyOnDetail();
        yield AssociationField::new('updatedBy', new TranslatableMessage('label.updated_by', domain: 'messages'))
            ->onlyOnDetail();
    }
}
