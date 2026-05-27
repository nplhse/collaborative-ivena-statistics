<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Blog;

use App\Content\Domain\Entity\PostComment;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @extends AbstractCrudController<PostComment>
 */
#[IsGranted('ROLE_ADMIN')]
final class PostCommentCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return PostComment::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('label.blog.comment')
            ->setEntityLabelInPlural('label.blog.comments')
            ->setSearchFields(['id', 'content', 'author.username', 'post.title'])
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnDetail();
        yield AssociationField::new('post', 'label.post')->setDisabled();
        yield AssociationField::new('author', 'label.author')->setDisabled();
        yield AssociationField::new('parent', 'label.parent')->setDisabled()->hideOnIndex();
        yield TextareaField::new('content', 'label.content')->setDisabled();
        yield DateTimeField::new('createdAt', 'label.created')->setFormat('dd.MM.yy HH:mm');
    }
}
