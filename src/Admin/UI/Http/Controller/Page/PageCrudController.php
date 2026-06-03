<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Page;

use App\Admin\UI\Form\PageContentBlockType;
use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use App\Content\Application\Page\PageContentBlockDataNormalizer;
use App\Content\Application\Page\PageContentMediaResolver;
use App\Content\Application\Page\PageContentSanitizer;
use App\Content\Application\Page\PageContentValidator;
use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
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
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
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
        private readonly PageContentBlockDataNormalizer $pageContentBlockDataNormalizer,
        private readonly PageContentValidator $pageContentValidator,
        private readonly PageContentMediaResolver $pageContentMediaResolver,
        private readonly PageContentSanitizer $pageContentSanitizer,
        private readonly PagePathResolver $pagePathResolver,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
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
            ->setSearchFields(['id', 'title', 'slug', 'path', 'key'])
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
        yield SlugField::new('slug', 'label.slug')->setTargetFieldName('title')->hideOnIndex();
        yield ChoiceField::new('key', 'label.page_key')
            ->setChoices($this->buildPageKeyChoices())
            ->setRequired(false)
            ->allowMultipleChoices(false)
            ->setFormTypeOption('placeholder', '—');
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
        yield IntegerField::new('sortOrder', 'label.sort_order')->hideOnIndex();
        yield TextField::new('path', 'label.path')
            ->hideOnForm()
            ->hideOnIndex();
        yield CollectionField::new('content', 'label.content_blocks')
            ->setHelp($this->buildMediaLibraryHelp())
            ->setFormTypeOption('help_html', true)
            ->setEntryType(PageContentBlockType::class)
            ->setEntryIsComplex()
            ->setEntryToStringMethod(function (mixed $value): string {
                if (!is_array($value)) {
                    return $this->translator->trans('label.block');
                }

                $type = (string) ($value['type'] ?? 'block');
                $enabled = (bool) ($value['enabled'] ?? true);
                $state = $this->translator->trans($enabled ? 'label.enabled' : 'label.disabled');

                return sprintf('%s (%s)', $this->formatBlockTypeLabel($type), $state);
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
        if ($this->pageHasSlug($page)) {
            $this->pagePathResolver->synchronize($page);
        }

        $content = $this->pageContentBlockDataNormalizer->normalize($page->getContent());
        $content = $this->pageContentMediaResolver->resolve($content);
        $this->pageContentValidator->assertValid($content);
        $page->setContent($this->pageContentSanitizer->sanitize($content));
    }

    /**
     * @return array<string, PageKey>
     */
    private function buildPageKeyChoices(): array
    {
        $choices = [];
        foreach (PageKey::cases() as $pageKey) {
            $choices[$pageKey->translationKey()] = $pageKey;
        }

        return $choices;
    }

    /**
     * @param FormBuilderInterface<Page> $builder
     */
    private function addPathSynchronizationListener(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $page = $event->getData();
            if (!$page instanceof Page) {
                return;
            }

            if (!$this->pageHasSlug($page)) {
                return;
            }

            $this->pagePathResolver->synchronize($page);
        }, 512);
    }

    private function pageHasSlug(Page $page): bool
    {
        $slug = $page->getSlug();

        return null !== $slug && '' !== trim($slug);
    }

    private function formatBlockTypeLabel(string $type): string
    {
        $blockType = \App\Content\Domain\Enum\PageContentBlockType::tryFromString($type);

        if ($blockType instanceof \App\Content\Domain\Enum\PageContentBlockType) {
            return $this->translator->trans($blockType->translationKey());
        }

        return $this->translator->trans('label.block_type.richtext');
    }

    private function buildMediaLibraryHelp(): string
    {
        $url = htmlspecialchars(
            $this->adminUrlGenerator
                ->setController(MediaCrudController::class)
                ->generateUrl(),
            ENT_QUOTES | ENT_HTML5,
        );

        return $this->translator->trans('help.page.media_library')
            .sprintf(' <a href="%s" target="_blank" rel="noopener">%s</a>.', $url, $this->translator->trans('label.media_library'));
    }
}
