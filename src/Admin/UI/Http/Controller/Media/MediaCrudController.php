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
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly TranslatorInterface $translator,
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
            ->setEntityLabelInSingular(new TranslatableMessage('label.media', domain: 'content'))
            ->setEntityLabelInPlural(new TranslatableMessage('label.media_library', domain: 'content'))
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
        yield TextField::new('file', new TranslatableMessage('label.media_file', domain: 'content'))
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
        yield IntegerField::new('width', 'label.width')->onlyOnDetail();
        yield IntegerField::new('height', 'label.height')->onlyOnDetail();
        yield ChoiceField::new('type', new TranslatableMessage('label.media_type', domain: 'content'))
            ->setChoices($this->mediaTypeChoices())
            ->renderAsBadges()
            ->hideOnForm();
        yield TextField::new('title', 'label.title');
        yield TextField::new('altText', new TranslatableMessage('label.image_alt', domain: 'content'))
            ->setHelp(new TranslatableMessage('help.media.alt_text', domain: 'content'))
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

    /**
     * @return array<string, MediaType>
     */
    private function mediaTypeChoices(): array
    {
        return [
            $this->translator->trans('label.media_type.image', [], 'content') => MediaType::IMAGE,
            $this->translator->trans('label.media_type.pdf', [], 'content') => MediaType::PDF,
            $this->translator->trans('label.media_type.document', [], 'content') => MediaType::DOCUMENT,
        ];
    }
}
