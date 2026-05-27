<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Blog;

use App\Content\Domain\Entity\Post;
use App\Content\Domain\Enum\PostStatus;
use App\Content\Infrastructure\Repository\PostRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
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

/**
 * @extends AbstractCrudController<Post>
 */
#[IsGranted('ROLE_ADMIN')]
final class PostCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly PostRepository $postRepository,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Post::class;
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
        yield TextField::new('slug', 'label.slug')->setDisabled();
        yield AssociationField::new('category', 'label.category');
        yield AssociationField::new('tags', 'label.tags')->autocomplete();
        yield ChoiceField::new('status', 'label.status')
            ->setChoices([
                'label.draft' => PostStatus::DRAFT,
                'label.published' => PostStatus::PUBLISHED,
            ])
            ->renderAsBadges();
        yield DateTimeField::new('publishedAt', 'label.published_at')
            ->setHelp('help.blog.published_at');
        yield TextEditorField::new('content', 'label.content')
            ->setNumOfRows(20);
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
}
