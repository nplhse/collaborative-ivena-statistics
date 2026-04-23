<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Blog;

use App\Content\Domain\Entity\PostCategory;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * @extends AbstractCrudController<PostCategory>
 */
final class PostCategoryCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return PostCategory::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('label.blog.category')
            ->setEntityLabelInPlural('label.blog.categories')
            ->setSearchFields(['id', 'name', 'slug'])
            ->setDefaultSort(['name' => 'ASC']);
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
        yield TextField::new('name', 'label.name');
        yield TextField::new('slug', 'label.slug')->setDisabled();
        yield DateTimeField::new('createdAt', 'label.created')->setFormat('dd.MM.yy HH:mm')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'label.updated')->setFormat('dd.MM.yy HH:mm')->hideOnForm();
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        /* @var PostCategory $entityInstance */
        $entityInstance->setSlug(strtolower(new AsciiSlugger()->slug((string) $entityInstance->getName())->toString()));

        parent::persistEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        /* @var PostCategory $entityInstance */
        $entityInstance->setSlug(strtolower(new AsciiSlugger()->slug((string) $entityInstance->getName())->toString()));

        parent::updateEntity($entityManager, $entityInstance);
    }
}
