<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Infrastructure\Repository\MediaRepository;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\TextEditorType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds type-specific fields inside a page content block "data" sub-form.
 */
final readonly class PageContentBlockDataFieldsConfigurator
{
    /** @var list<string> */
    public const array DATA_FIELD_NAMES = [
        'html', 'src', 'alt', 'caption', 'headline', 'buttonLabel', 'buttonUrl',
        'mediaId', 'linkType', 'openInNewTab', 'text',
    ];

    /** @psalm-suppress PossiblyUnusedMethod Symfony autowires this service */
    public function __construct(
        private MediaRepository $mediaRepository,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    public function replaceFieldsForType(FormInterface $dataForm, ?string $type): void
    {
        foreach (self::DATA_FIELD_NAMES as $fieldName) {
            if ($dataForm->has($fieldName)) {
                $dataForm->remove($fieldName);
            }
        }

        match ($type) {
            'image' => $this->addImageFields($dataForm),
            'cta' => $this->addCtaFields($dataForm),
            'quote' => $this->addQuoteFields($dataForm),
            default => $this->addRichtextFields($dataForm),
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function hydrateMediaReference(array $data): array
    {
        $mediaId = $data['mediaId'] ?? null;
        if (is_int($mediaId) || (is_string($mediaId) && ctype_digit($mediaId))) {
            $media = $this->mediaRepository->find((int) $mediaId);
            if ($media instanceof Media) {
                $data['mediaId'] = $media;
            }
        }

        return $data;
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addRichtextFields(FormInterface $dataForm): void
    {
        $dataForm->add('html', TextEditorType::class, [
            'label' => 'label.html',
            'required' => true,
            'attr' => ['rows' => 20],
            'help' => 'help.page.richtext_allowed_tags',
        ]);
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addImageFields(FormInterface $dataForm): void
    {
        $mediaLibraryUrl = $this->urlGenerator->generate('app_admin_dashboard').'?crudAction=index&crudControllerFqcn='.urlencode(MediaCrudController::class);

        $dataForm
            ->add('mediaId', EntityType::class, [
                'class' => Media::class,
                'label' => 'label.media_image',
                'required' => false,
                'choice_label' => static fn (Media $media): string => (string) $media,
                'query_builder' => fn (): \Doctrine\ORM\QueryBuilder => $this->mediaRepository->createQueryBuilder('m')
                    ->andWhere('m.type = :type')
                    ->setParameter('type', MediaType::IMAGE)
                    ->orderBy('m.createdAt', 'DESC'),
                'help' => $this->translator->trans('help.page.image_media')
                    .sprintf(
                        ' <a href="%s" target="_blank" rel="noopener">%s</a>.',
                        htmlspecialchars($mediaLibraryUrl, ENT_QUOTES | ENT_HTML5),
                        $this->translator->trans('label.media_library'),
                    ),
                'help_html' => true,
            ])
            ->add('src', TextType::class, [
                'label' => 'label.image_url',
                'required' => false,
                'help' => 'help.page.image_url_legacy',
            ])
            ->add('alt', TextType::class, [
                'label' => 'label.image_alt',
                'required' => true,
            ])
            ->add('caption', TextType::class, [
                'label' => 'label.image_caption',
                'required' => false,
            ]);
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addCtaFields(FormInterface $dataForm): void
    {
        $dataForm
            ->add('headline', TextType::class, [
                'label' => 'label.headline',
                'required' => true,
            ])
            ->add('buttonLabel', TextType::class, [
                'label' => 'label.button_label',
                'required' => true,
            ])
            ->add('linkType', ChoiceType::class, [
                'label' => 'label.cta_link_type',
                'choices' => [
                    'label.cta_link_type.url' => 'url',
                    'label.cta_link_type.media' => 'media',
                ],
                'empty_data' => 'url',
            ])
            ->add('buttonUrl', TextType::class, [
                'label' => 'label.button_url',
                'required' => false,
                'help' => 'help.page.cta_button_url',
            ])
            ->add('mediaId', EntityType::class, [
                'class' => Media::class,
                'label' => 'label.media_pdf',
                'required' => false,
                'choice_label' => static fn (Media $media): string => (string) $media,
                'query_builder' => fn (): \Doctrine\ORM\QueryBuilder => $this->mediaRepository->createQueryBuilder('m')
                    ->andWhere('m.type = :type')
                    ->setParameter('type', MediaType::PDF)
                    ->orderBy('m.createdAt', 'DESC'),
                'help' => 'help.page.cta_media_pdf',
            ])
            ->add('openInNewTab', CheckboxType::class, [
                'label' => 'label.open_in_new_tab',
                'required' => false,
            ]);
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addQuoteFields(FormInterface $dataForm): void
    {
        $dataForm->add('text', TextareaType::class, [
            'label' => 'label.quote_text',
            'required' => true,
            'attr' => ['rows' => 4],
        ]);
    }
}
