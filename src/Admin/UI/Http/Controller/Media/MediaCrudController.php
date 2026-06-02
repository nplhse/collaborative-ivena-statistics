<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\Media;

use App\Content\Application\Media\MediaSnippetGenerator;
use App\Content\Application\Media\MediaTypeResolver;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vich\UploaderBundle\Form\Type\VichFileType;

/**
 * @extends AbstractCrudController<Media>
 */
#[IsGranted('ROLE_ADMIN')]
final class MediaCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly MediaTypeResolver $mediaTypeResolver,
        private readonly MediaSnippetGenerator $mediaSnippetGenerator,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Media::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('label.media')
            ->setEntityLabelInPlural('label.media_library')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['originalFilename', 'title', 'altText', 'filename'])
            ->overrideTemplate('crud/detail', '@Admin/media/detail.html.twig');
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
        yield TextField::new('file', 'label.media_file')
            ->setFormType(VichFileType::class)
            ->setFormTypeOptions([
                'required' => Crud::PAGE_NEW === $pageName,
                'allow_delete' => false,
                'download_uri' => false,
            ])
            ->onlyOnForms();
        yield ImageField::new('filename', 'label.preview')
            ->setBasePath('/uploads/media')
            ->onlyOnDetail()
            ->hideOnForm()
            ->formatValue(static fn (?string $filename, Media $media): ?string => MediaType::IMAGE === $media->getType() ? $filename : null);
        yield TextField::new('originalFilename', 'label.original_filename')->hideOnForm()->hideOnIndex();
        yield TextField::new('filename', 'label.filename')->onlyOnDetail();
        yield TextField::new('mimeType', 'label.mime_type')->onlyOnDetail();
        yield IntegerField::new('size', 'label.file_size')->onlyOnDetail();
        yield ChoiceField::new('type', 'label.media_type')
            ->setChoices([
                'label.media_type.image' => MediaType::IMAGE,
                'label.media_type.pdf' => MediaType::PDF,
                'label.media_type.document' => MediaType::DOCUMENT,
            ])
            ->renderAsBadges()
            ->hideOnForm();
        yield TextField::new('title', 'label.title');
        yield TextField::new('altText', 'label.image_alt')
            ->setHelp('help.media.alt_text')
            ->hideOnIndex();
        yield AssociationField::new('createdBy', 'label.uploaded_by')->hideOnForm();
        yield DateTimeField::new('createdAt', 'label.created')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'label.updated')->hideOnForm();
    }

    #[\Override]
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $responseParameters = parent::configureResponseParameters($responseParameters);

        $context = $this->getContext();
        if (!$context instanceof AdminContext) {
            return $responseParameters;
        }

        $crud = $context->getCrud();
        if (!$crud instanceof \EasyCorp\Bundle\EasyAdminBundle\Dto\CrudDto || Crud::PAGE_DETAIL !== $crud->getCurrentPage()) {
            return $responseParameters;
        }

        $entity = $context->getEntity()->getInstance();
        if ($entity instanceof Media) {
            $responseParameters->set('media_snippet', $this->mediaSnippetGenerator->generateHtml($entity));
            $responseParameters->set('media_public_url', $this->mediaSnippetGenerator->getPublicUrl($entity));
        }

        return $responseParameters;
    }

    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->mediaTypeResolver->applyTo($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->mediaTypeResolver->applyTo($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }
}
