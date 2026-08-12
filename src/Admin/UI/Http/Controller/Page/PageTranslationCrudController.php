<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Page;

use App\Admin\UI\Form\PageContentBlockType;
use App\Content\Application\Contract\MediaLibraryAdminUrlProviderInterface;
use App\Content\Application\Page\PageContentBlockDataNormalizer;
use App\Content\Application\Page\PageContentMediaResolver;
use App\Content\Application\Page\PageContentSanitizer;
use App\Content\Application\Page\PageContentValidator;
use App\Content\Application\Page\PagePathResolver;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Entity\PageTranslation;
use App\Content\Infrastructure\Repository\PageRepository;
use App\Shared\Application\Locale\SupportedLocales;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractCrudController<PageTranslation>
 */
#[IsGranted('ROLE_ADMIN')]
final class PageTranslationCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PageContentBlockDataNormalizer $pageContentBlockDataNormalizer,
        private readonly PageContentValidator $pageContentValidator,
        private readonly PageContentMediaResolver $pageContentMediaResolver,
        private readonly PageContentSanitizer $pageContentSanitizer,
        private readonly PagePathResolver $pagePathResolver,
        private readonly MediaLibraryAdminUrlProviderInterface $mediaLibraryAdminUrlProvider,
        private readonly TranslatorInterface $translator,
        private readonly PageRepository $pageRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        #[Autowire('%app.content.default_locale%')]
        private readonly string $contentDefaultLocale,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return PageTranslation::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(new TranslatableMessage('label.page_translation', domain: 'content'))
            ->setEntityLabelInPlural(new TranslatableMessage('label.page_translations', domain: 'content'))
            ->setSearchFields(['id', 'title', 'slug', 'path', 'locale'])
            ->setDefaultSort(['path' => 'ASC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $this->createViewPublicAction())
            ->add(Crud::PAGE_DETAIL, $this->createViewPublicAction())
            ->add(Crud::PAGE_EDIT, $this->createViewPublicAction())
            ->add(Crud::PAGE_INDEX, $this->createBackToPageAction())
            ->add(Crud::PAGE_DETAIL, $this->createBackToPageAction())
            ->add(Crud::PAGE_EDIT, $this->createBackToPageAction());
    }

    private function createViewPublicAction(): Action
    {
        return Action::new('viewPublic', new TranslatableMessage('admin.page.action.view_public', domain: 'admin'), 'fas fa-external-link-alt')
            ->linkToRoute('app_page_show', static fn (PageTranslation $translation): array => [
                'path' => trim((string) $translation->getPath(), '/'),
            ])
            ->setHtmlAttributes(['target' => '_blank', 'rel' => 'noopener noreferrer'])
            ->displayIf(static function (PageTranslation $translation): bool {
                if (!$translation->isPublished()) {
                    return false;
                }

                return '' !== trim((string) $translation->getPath(), '/');
            });
    }

    private function createBackToPageAction(): Action
    {
        return Action::new('backToPage', new TranslatableMessage('admin.page.action.back_to_page', domain: 'admin'), 'fas fa-file')
            ->linkToUrl(function (PageTranslation $translation): string {
                $page = $translation->getPage();
                if (!$page instanceof Page || null === $page->getId()) {
                    return $this->adminUrlGenerator
                        ->setController(PageCrudController::class)
                        ->setAction(Action::INDEX)
                        ->generateUrl();
                }

                return $this->adminUrlGenerator
                    ->setController(PageCrudController::class)
                    ->setAction(Action::DETAIL)
                    ->setEntityId($page->getId())
                    ->unset('pageId')
                    ->unset('locale')
                    ->generateUrl();
            });
    }

    #[\Override]
    public function configureAssets(Assets $assets): Assets
    {
        return $assets
            ->addCssFile(Asset::fromEasyAdminAssetPackage('field-text-editor.css')->onlyOnForms())
            ->addJsFile(Asset::fromEasyAdminAssetPackage('field-text-editor.js')->onlyOnForms())
            ->addAssetMapperEntry(Asset::new('admin-page-form')->onlyOnForms());
    }

    #[\Override]
    public function createEntity(string $entityFqcn): PageTranslation
    {
        $translation = new PageTranslation();
        $request = $this->getContext()?->getRequest();
        $pageId = $request?->query->getInt('pageId') ?: null;
        $locale = $request?->query->getString('locale') ?: null;

        if (null !== $pageId && $pageId > 0) {
            $page = $this->pageRepository->find($pageId);
            if ($page instanceof Page) {
                $translation->setPage($page);
            }
        }

        if (null !== $locale && SupportedLocales::isSupported($locale)) {
            $translation->setLocale($locale);
        }

        $translation->setStatus(PageTranslation::STATUS_DRAFT);
        $translation->setContent([]);
        $translation->setTitle('');
        $translation->setSlug('');
        $translation->setPath('/pending-'.bin2hex(random_bytes(4)));

        return $translation;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield AssociationField::new('page', new TranslatableMessage('label.page', domain: 'content'))
            ->setCrudController(PageCrudController::class)
            ->setRequired(true);
        yield ChoiceField::new('locale', 'Locale')
            ->setChoices(array_combine(SupportedLocales::ALL, SupportedLocales::ALL))
            ->setRequired(true);
        yield TextField::new('title', 'label.title');
        yield TextField::new('slug', 'label.slug')
            ->setRequired(false)
            ->setHelp(new TranslatableMessage('help.page.slug', domain: 'content'))
            ->hideOnIndex();
        yield ChoiceField::new('status', 'label.status')
            ->setChoices([
                'Draft' => PageTranslation::STATUS_DRAFT,
                'Published' => PageTranslation::STATUS_PUBLISHED,
            ])
            ->renderAsBadges();
        yield BooleanField::new('showToc', new TranslatableMessage('label.show_toc', domain: 'content'))
            ->setHelp(new TranslatableMessage('help.page.show_toc', domain: 'content'));
        yield TextField::new('path', 'label.path')
            ->hideOnForm()
            ->hideOnIndex();
        yield AssociationField::new('page.translations', new TranslatableMessage('admin.page_translation.relations', domain: 'admin'))
            ->setTemplatePath('@Admin/page_translation/relations_panel.html.twig')
            ->onlyOnDetail();
        yield CollectionField::new('content', 'label.content_blocks')
            ->setHelp($this->buildMediaLibraryHelp().' '.$this->translator->trans('help.page.content_blocks_reorder', [], 'content'))
            ->setFormTypeOption('help_html', true)
            ->setEntryType(PageContentBlockType::class)
            ->setEntryIsComplex()
            ->setFormTypeOption('row_attr', [
                'data-controller' => 'collection-reorder',
                'data-collection-reorder-move-up-label-value' => $this->translator->trans('label.move_block_up', [], 'messages'),
                'data-collection-reorder-move-down-label-value' => $this->translator->trans('label.move_block_down', [], 'messages'),
            ])
            ->setEntryToStringMethod(function (mixed $value): string {
                if (!is_array($value)) {
                    return $this->translator->trans('label.block', [], 'messages');
                }

                $type = (string) ($value['type'] ?? 'block');
                $enabled = (bool) ($value['enabled'] ?? true);
                $state = $this->translator->trans($enabled ? 'label.enabled' : 'label.disabled', [], 'messages');

                return sprintf('%s (%s)', $this->formatBlockTypeLabel($type), $state);
            })
            ->showEntryLabel()
            ->onlyOnForms();
        yield DateTimeField::new('createdAt', 'label.created')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'label.updated')->hideOnForm();
    }

    /**
     * @return FormBuilderInterface<PageTranslation>
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
     * @return FormBuilderInterface<PageTranslation>
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
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof PageTranslation) {
            return;
        }

        $this->prepareContent($entityInstance);
        $this->syncLegacyPageFields($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if (!$entityInstance instanceof PageTranslation) {
            return;
        }

        $this->prepareContent($entityInstance);
        $this->syncLegacyPageFields($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function prepareContent(PageTranslation $translation): void
    {
        if ($this->shouldSynchronizePath($translation)) {
            $this->pagePathResolver->synchronize($translation);
        }

        $content = $this->pageContentBlockDataNormalizer->normalize($translation->getContent());
        $content = $this->pageContentMediaResolver->resolve($content);
        $this->pageContentValidator->assertValid($content);
        $translation->setContent($this->pageContentSanitizer->sanitize($content));
    }

    /**
     * Keep transitional Page legacy columns aligned with the content default locale translation.
     */
    private function syncLegacyPageFields(PageTranslation $translation): void
    {
        $page = $translation->getPage();
        if (!$page instanceof Page) {
            return;
        }

        $defaultLocale = $this->contentDefaultLocale;
        if ($translation->getLocale() !== $defaultLocale) {
            return;
        }

        $page->setTitle((string) $translation->getTitle());
        $page->setSlug((string) $translation->getSlug());
        $page->setPath((string) $translation->getPath());
        $page->setStatus($translation->getStatus());
        $page->setContent($translation->getContent());
    }

    /**
     * @param FormBuilderInterface<PageTranslation> $builder
     */
    private function addPathSynchronizationListener(FormBuilderInterface $builder): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $translation = $event->getData();
            if (!$translation instanceof PageTranslation) {
                return;
            }

            if (!$this->shouldSynchronizePath($translation)) {
                return;
            }

            try {
                $this->pagePathResolver->synchronize($translation);
            } catch (\InvalidArgumentException) {
                // Parent translation missing: validation will reject publish.
            }
        }, 512);
    }

    private function shouldSynchronizePath(PageTranslation $translation): bool
    {
        return '' !== trim((string) $translation->getTitle());
    }

    private function formatBlockTypeLabel(string $type): string
    {
        $blockType = \App\Content\Domain\Enum\PageContentBlockType::tryFromString($type);

        if ($blockType instanceof \App\Content\Domain\Enum\PageContentBlockType) {
            return $this->translator->trans($blockType->translationKey(), [], 'content');
        }

        return $this->translator->trans('label.block_type.richtext', [], 'content');
    }

    private function buildMediaLibraryHelp(): string
    {
        $url = htmlspecialchars(
            $this->mediaLibraryAdminUrlProvider->getIndexUrl(),
            ENT_QUOTES | ENT_HTML5,
        );

        return $this->translator->trans('help.page.media_library', [], 'content')
            .sprintf(' <a href="%s" target="_blank" rel="noopener">%s</a>.', $url, $this->translator->trans('label.media_library', [], 'content'));
    }

    #[\Override]
    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        $entity = $context->getEntity()->getInstance();
        if ($entity instanceof PageTranslation) {
            $page = $entity->getPage();
            if ($page instanceof Page) {
                $url = $this->adminUrlGenerator
                    ->setController(PageCrudController::class)
                    ->setAction(Action::DETAIL)
                    ->setEntityId($page->getId())
                    ->unset('pageId')
                    ->unset('locale')
                    ->generateUrl();

                return $this->redirect($url);
            }
        }

        return parent::getRedirectResponseAfterSave($context, $action);
    }
}
