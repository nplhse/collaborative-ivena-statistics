<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Page;

use App\Admin\UI\Form\PageContentBlockType;
use App\Content\Application\Page\PageContentSanitizer;
use App\Content\Application\Page\PageContentValidator;
use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractCrudController<Page>
 */
#[IsGranted('ROLE_ADMIN')]
final class PageCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PageContentValidator $pageContentValidator,
        private readonly PageContentSanitizer $pageContentSanitizer,
        private readonly PagePathResolver $pagePathResolver,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Page::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('label.page')
            ->setEntityLabelInPlural('label.pages')
            ->setSearchFields(['id', 'title', 'slug', 'path'])
            ->setDefaultSort(['path' => 'ASC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $this->createViewPublicAction())
            ->update(Crud::PAGE_DETAIL, Action::INDEX, static fn (Action $action): Action => $action->setLabel('admin.page.action.back_to_index'))
            ->add(Crud::PAGE_DETAIL, $this->createViewPublicAction())
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->update(Crud::PAGE_EDIT, Action::INDEX, static fn (Action $action): Action => $action->setLabel('admin.page.action.back_to_index'))
            ->add(Crud::PAGE_EDIT, $this->createViewPublicAction());
    }

    private function createViewPublicAction(): Action
    {
        return Action::new('viewPublic', 'admin.page.action.view_public', 'fas fa-external-link-alt')
            ->linkToRoute('app_page_show', static fn (Page $page): array => ['path' => trim((string) $page->getPath(), '/')])
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener noreferrer'])
            ->displayIf(static function (Page $page): bool {
                if (Page::STATUS_PUBLISHED !== $page->getStatus()) {
                    return false;
                }

                return '' !== trim((string) $page->getPath(), '/');
            });
    }

    /** TextEditorType in {@see PageContentBlockType} does not pull field assets; mirror TextEditorField. */
    #[\Override]
    public function configureAssets(Assets $assets): Assets
    {
        return $assets
            ->addCssFile(Asset::fromEasyAdminAssetPackage('field-text-editor.css')->onlyOnForms())
            ->addJsFile(Asset::fromEasyAdminAssetPackage('field-text-editor.js')->onlyOnForms());
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('title', 'label.title');
        yield SlugField::new('slug', 'label.slug')->setTargetFieldName('title');
        yield AssociationField::new('parent', 'label.parent_page')->autocomplete();
        yield ChoiceField::new('status', 'label.status')
            ->setChoices([
                'Draft' => Page::STATUS_DRAFT,
                'Published' => Page::STATUS_PUBLISHED,
            ])
            ->renderAsBadges();
        yield ChoiceField::new('visibility', 'label.visibility')
            ->setChoices([
                'label.public' => Page::VISIBILITY_PUBLIC,
                'label.authenticated' => Page::VISIBILITY_AUTHENTICATED,
            ]);
        yield IntegerField::new('sortOrder', 'label.sort_order');
        yield TextField::new('path', 'label.path')
            ->hideOnForm();
        yield CollectionField::new('content', 'label.content_blocks')
            ->setEntryType(PageContentBlockType::class)
            ->setEntryIsComplex()
            ->setEntryToStringMethod(static function (mixed $value, TranslatorInterface $translator): string {
                if (!is_array($value)) {
                    return $translator->trans('label.block');
                }

                $type = (string) ($value['type'] ?? 'block');
                $enabled = (bool) ($value['enabled'] ?? true);
                $state = $translator->trans($enabled ? 'label.enabled' : 'label.disabled');

                return sprintf('%s (%s)', $type, $state);
            })
            ->showEntryLabel()
            ->onlyOnForms();
        yield DateTimeField::new('createdAt', 'label.created')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'label.updated')->hideOnForm();
    }

    /**
     * @return FormBuilderInterface<Page>
     */
    #[\Override]
    public function createNewFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        $builder = parent::createNewFormBuilder($entityDto, $formOptions, $context);
        $this->addPathSynchronizationListener($builder);

        return $builder;
    }

    /**
     * @return FormBuilderInterface<Page>
     */
    #[\Override]
    public function createEditFormBuilder(EntityDto $entityDto, KeyValueStore $formOptions, AdminContext $context): FormBuilderInterface
    {
        /** @psalm-suppress ArgumentTypeCoercion */
        $builder = parent::createEditFormBuilder($entityDto, $formOptions, $context);
        $this->addPathSynchronizationListener($builder);

        return $builder;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Page) {
            return;
        }

        $this->prepareContent($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Page) {
            return;
        }

        $this->prepareContent($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function prepareContent(Page $page): void
    {
        $content = $page->getContent();
        $this->pageContentValidator->assertValid($content);
        $page->setContent($this->pageContentSanitizer->sanitize($content));
    }

    /**
     * @param FormBuilderInterface<Page> $builder
     */
    private function addPathSynchronizationListener(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $page = $event->getData();
            if (!$page instanceof Page) {
                return;
            }

            $this->pagePathResolver->synchronize($page);
        });
    }
}
