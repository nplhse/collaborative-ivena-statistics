<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Blog;

use App\Content\Application\Blog\PostContentSanitizer;
use App\Content\Application\Media\MediaLibraryAdminUrlProvider;
use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractCrudController<Post>
 */
#[IsGranted('ROLE_ADMIN')]
final class PostCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PostRepository $postRepository,
        private readonly TranslatorInterface $translator,
        private readonly PostContentSanitizer $postContentSanitizer,
        private readonly MediaLibraryAdminUrlProvider $mediaLibraryAdminUrlProvider,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Post::class;
    }

    #[\Override]
    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addAssetMapperEntry(Asset::new('admin-trix-media')->onlyOnForms());
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('label.blog.post')
            ->setEntityLabelInPlural('label.blog.posts')
            ->setSearchFields(['id', 'title', 'slug', 'category.name', 'tags.name'])
            ->setDefaultSort(['publishedAt' => 'DESC']);
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
        yield IdField::new('id')->onlyOnDetail();
        yield TextField::new('title', 'label.title');
        yield TextField::new('slug', 'label.slug')->setDisabled()->hideOnIndex();
        yield AssociationField::new('category', 'label.category');
        yield AssociationField::new('tags', 'label.tags')->autocomplete()->hideOnIndex();
        yield ChoiceField::new('status', 'label.status')
            ->setChoices([
                'label.draft' => PostStatus::DRAFT,
                'label.published' => PostStatus::PUBLISHED,
            ])
            ->renderAsBadges();
        yield DateTimeField::new('publishedAt', 'label.published_at')
            ->setHelp('help.blog.published_at');
        yield TextEditorField::new('content', 'label.content')
            ->setNumOfRows(20)
            ->setTrixEditorConfig([
                'dompurify' => [
                    'ADD_TAGS' => ['img', 'figure', 'figcaption'],
                    'ADD_ATTR' => ['src', 'alt', 'loading', 'class', 'data-fslightbox', 'href', 'target', 'rel'],
                ],
            ])
            ->setHelp($this->buildContentHelp())
            ->setFormTypeOption('help_html', true)
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'label.created')->setFormat('dd.MM.yy HH:mm')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'label.updated')->setFormat('dd.MM.yy HH:mm')->hideOnForm();
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Post) {
            return;
        }

        $this->ensurePublishDataConsistency($entityInstance);
        $entityInstance->setSlug($this->buildUniqueSlug($entityInstance, null));
        $entityInstance->setContent($this->postContentSanitizer->sanitize((string) $entityInstance->getContent()));

        parent::persistEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Post) {
            return;
        }

        $this->ensurePublishDataConsistency($entityInstance);
        $entityInstance->setSlug($this->buildUniqueSlug($entityInstance, $entityInstance->getId()));
        $entityInstance->setContent($this->postContentSanitizer->sanitize((string) $entityInstance->getContent()));

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function ensurePublishDataConsistency(Post $post): void
    {
        if (PostStatus::PUBLISHED === $post->getStatus() && !$post->getPublishedAt() instanceof \DateTimeImmutable) {
            $post->setPublishedAt(new \DateTimeImmutable('now'));
        }
    }

    private function buildUniqueSlug(Post $post, ?int $excludeId): string
    {
        $title = (string) $post->getTitle();
        $base = strtolower(new AsciiSlugger()->slug($title)->toString());
        $candidate = $base;
        $counter = 2;

        while ($this->postRepository->slugExists($candidate, $excludeId)) {
            $candidate = $base.'-'.$counter;
            ++$counter;
        }

        return $candidate;
    }

    private function buildContentHelp(): string
    {
        return $this->buildMediaLibraryHelp()
            .' '.$this->translator->trans('help.blog.image_layout');
    }

    private function buildMediaLibraryHelp(): string
    {
        $url = htmlspecialchars(
            $this->mediaLibraryAdminUrlProvider->getIndexUrl(),
            ENT_QUOTES | ENT_HTML5,
        );

        return $this->translator->trans('help.blog.media_library')
            .sprintf(' <a href="%s" target="_blank" rel="noopener">%s</a>.', $url, $this->translator->trans('label.media_library'));
    }
}
