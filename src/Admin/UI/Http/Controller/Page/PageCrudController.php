<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Page;

use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Shared\Application\Locale\SupportedLocales;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Structural page identity (hierarchy, key, visibility). Locale content lives in PageTranslation CRUD.
 *
 * @extends AbstractCrudController<Page>
 */
#[IsGranted('ROLE_ADMIN')]
final class PageCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly AdminUrlGenerator $adminUrlGenerator,
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
            ->setEntityLabelInSingular(new TranslatableMessage('label.page', domain: 'content'))
            ->setEntityLabelInPlural(new TranslatableMessage('label.pages', domain: 'content'))
            ->setSearchFields(['id', 'key', 'title'])
            ->setDefaultSort(['id' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_DETAIL, Action::INDEX, fn (Action $action): Action => $action->setLabel(new TranslatableMessage('admin.page.action.back_to_index', domain: 'admin')))
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->update(Crud::PAGE_EDIT, Action::INDEX, fn (Action $action): Action => $action->setLabel(new TranslatableMessage('admin.page.action.back_to_index', domain: 'admin')))
            ->add(Crud::PAGE_DETAIL, $this->createAddTranslationAction())
            ->add(Crud::PAGE_INDEX, $this->createAddTranslationAction());
    }

    private function createAddTranslationAction(): Action
    {
        return Action::new('addTranslation', new TranslatableMessage('admin.page.action.add_translation', domain: 'admin'), 'fas fa-language')
            ->linkToUrl(fn (Page $page): string => $this->adminUrlGenerator
                ->setController(PageTranslationCrudController::class)
                ->setAction(Action::NEW)
                ->unset('entityId')
                ->set('pageId', (string) $page->getId())
                ->generateUrl())
            ->displayIf(static fn (Page $page): bool => array_any(SupportedLocales::ALL, fn (string $locale): bool => !$page->hasTranslation($locale)));
    }

    #[\Override]
    public function createEntity(string $entityFqcn): Page
    {
        $page = new Page();
        $suffix = bin2hex(random_bytes(4));
        // Transitional legacy NOT NULL columns until Phase 4 cleanup.
        /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
        $page->setTitle('Untitled page');
        /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
        $page->setSlug('untitled-'.$suffix);
        /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
        $page->setPath('/untitled-'.$suffix);
        /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
        $page->setStatus(Page::STATUS_DRAFT);
        /** @psalm-suppress DeprecatedMethod Transitional until Phase 4 */
        $page->setContent([]);

        return $page;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield ChoiceField::new('key', new TranslatableMessage('label.page_key', domain: 'content'))
            ->setChoices($this->buildPageKeyChoices())
            ->setRequired(false)
            ->allowMultipleChoices(false)
            ->setFormTypeOption('placeholder', '—');
        yield AssociationField::new('parent', 'label.parent_page')->autocomplete();
        yield ChoiceField::new('visibility', 'label.visibility')
            ->setChoices([
                'label.public' => Page::VISIBILITY_PUBLIC,
                'label.authenticated' => Page::VISIBILITY_AUTHENTICATED,
            ]);
        yield IntegerField::new('sortOrder', 'label.sort_order')->hideOnIndex();
        yield AssociationField::new('translations', new TranslatableMessage('label.page_translations', domain: 'content'))
            ->setTemplatePath('@Admin/page/translations_panel.html.twig')
            ->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'label.created')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'label.updated')->hideOnForm();
    }

    /**
     * @return array<string, PageKey>
     */
    private function buildPageKeyChoices(): array
    {
        $choices = [];
        foreach (PageKey::cases() as $pageKey) {
            $choices[$this->translator->trans($pageKey->translationKey(), [], 'content')] = $pageKey;
        }

        return $choices;
    }
}
