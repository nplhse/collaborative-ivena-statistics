<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Page;

use App\Content\Application\Contract\HelpFeedbackStatsQueryInterface;
use App\Content\Domain\Entity\Page;
use App\Content\Domain\Enum\PageKey;
use App\Content\Domain\Enum\PageType;
use App\Shared\Application\Locale\SupportedLocales;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Help articles (#596): pages of type help below the help start page, kept apart from the other pages. Their content
 * is edited in the page translation CRUD like every page.
 *
 * @extends AbstractCrudController<Page>
 */
#[IsGranted('ROLE_ADMIN')]
final class HelpArticleCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly HelpFeedbackStatsQueryInterface $feedbackStats,
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
            ->setEntityLabelInSingular(new TranslatableMessage('label.help_article', domain: 'content'))
            ->setEntityLabelInPlural(new TranslatableMessage('label.help_articles', domain: 'content'))
            ->setSearchFields(['id', 'helpKey', 'translations.title', 'translations.path'])
            ->setDefaultSort(['parent' => 'ASC', 'sortOrder' => 'ASC', 'id' => 'ASC']);
    }

    #[\Override]
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->andWhere('entity.type = :helpType')
            ->setParameter('helpType', PageType::Help);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $addTranslation = Action::new('addTranslation', new TranslatableMessage('admin.page.action.add_translation', domain: 'admin'), 'fas fa-language')
            ->linkToUrl(fn (Page $page): string => $this->adminUrlGenerator
                ->setController(PageTranslationCrudController::class)
                ->setAction(Action::NEW)
                ->unset('entityId')
                ->set('pageId', (string) $page->getId())
                ->generateUrl())
            ->displayIf(static fn (Page $page): bool => array_any(SupportedLocales::ALL, fn (string $locale): bool => !$page->hasTranslation($locale)));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_EDIT, Action::INDEX)
            ->add(Crud::PAGE_DETAIL, $addTranslation)
            ->add(Crud::PAGE_INDEX, $addTranslation);
    }

    /** New articles start below the help start page; editors move them into a topic with the parent field. */
    #[\Override]
    public function createEntity(string $entityFqcn): Page
    {
        $page = new Page()->setType(PageType::Help);
        $root = $this->entityManager->getRepository(Page::class)->findOneBy(['key' => PageKey::Help]);

        return $root instanceof Page ? $page->setParent($root) : $page;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        // Virtual fields: a generic Field on a mapped property is replaced by its guessed type, which drops the template.
        yield Field::new('articleTitle', new TranslatableMessage('label.help_article', domain: 'content'))
            ->setVirtual(true)
            ->onlyOnIndex()
            ->setSortable(false)
            ->formatValue(static fn (mixed $value, Page $page): string => (string) $page)
            ->setTemplatePath('@Admin/help_article/_formatted_value.html.twig');
        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.identity', domain: 'admin'));
        yield AssociationField::new('parent', new TranslatableMessage('label.help_parent', domain: 'content'))
            ->setRequired(true)
            ->setQueryBuilder(static fn (QueryBuilder $queryBuilder): QueryBuilder => $queryBuilder
                ->andWhere('entity.type = :helpType OR entity.key = :helpKey')
                ->setParameter('helpType', PageType::Help)
                ->setParameter('helpKey', PageKey::Help));
        yield TextField::new('helpKey', new TranslatableMessage('label.help_key', domain: 'content'))
            ->setRequired(false)
            ->setHelp(new TranslatableMessage('label.help_key_help', domain: 'content'));
        yield ChoiceField::new('visibility', 'label.visibility')
            ->setChoices([
                'label.public' => Page::VISIBILITY_PUBLIC,
                'label.authenticated' => Page::VISIBILITY_AUTHENTICATED,
            ]);
        yield IntegerField::new('sortOrder', 'label.sort_order');
        yield Field::new('helpfulVotes', new TranslatableMessage('label.help_feedback', domain: 'content'))
            ->setVirtual(true)
            ->onlyOnIndex()
            ->setSortable(false)
            ->addCssClass('text-nowrap')
            ->formatValue(fn (mixed $value, Page $page): string => $this->formatVotes($page))
            ->setTemplatePath('@Admin/help_article/_formatted_value.html.twig');

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.translations', domain: 'admin'));
        yield AssociationField::new('translations', new TranslatableMessage('label.page_translations', domain: 'content'))
            ->setTemplatePath('@Admin/page/translations_panel.html.twig')
            ->onlyOnDetail();

        yield FormField::addFieldset(new TranslatableMessage('admin.fieldset.metadata', domain: 'admin'));
        yield IdField::new('id')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'label.created')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'label.updated')->onlyOnDetail();
    }

    private function formatVotes(Page $page): string
    {
        $counts = $this->feedbackStats->countsByPage()[$page->getId() ?? 0] ?? ['helpful' => 0, 'unhelpful' => 0];

        return \sprintf('👍 %d · 👎 %d', $counts['helpful'], $counts['unhelpful']);
    }
}
