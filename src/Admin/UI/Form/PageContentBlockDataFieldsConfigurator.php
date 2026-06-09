<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use App\Content\Domain\Entity\Media;
use App\Content\Domain\Enum\MediaType;
use App\Content\Domain\Enum\PageContentBlockType;
use App\Content\Infrastructure\Repository\MediaRepository;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\TextEditorType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
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
        'level', 'align', 'spacingBefore', 'spacingAfter',
        'variant', 'title', 'iconMode', 'icon',
        'size', 'float',
        'items',
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
            PageContentBlockType::Image->value => $this->addImageFields($dataForm),
            PageContentBlockType::Cta->value => $this->addCtaFields($dataForm),
            PageContentBlockType::Quote->value => $this->addQuoteFields($dataForm),
            PageContentBlockType::Headline->value => $this->addHeadlineFields($dataForm),
            PageContentBlockType::Highlight->value => $this->addHighlightFields($dataForm),
            PageContentBlockType::Accordion->value => $this->addAccordionFields($dataForm),
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
            ])
            ->add('size', ChoiceType::class, [
                'label' => 'label.image_size',
                'choices' => [
                    'label.image_size.auto' => 'auto',
                    'label.image_size.sm' => 'sm',
                    'label.image_size.md' => 'md',
                    'label.image_size.lg' => 'lg',
                ],
                'empty_data' => 'auto',
                'help' => 'help.page.image_size',
            ])
            ->add('float', ChoiceType::class, [
                'label' => 'label.image_float',
                'choices' => [
                    'label.image_float.none' => 'none',
                    'label.image_float.left' => 'left',
                    'label.image_float.right' => 'right',
                ],
                'empty_data' => 'none',
                'help' => 'help.page.image_float',
            ])
        ;
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
            ])
        ;
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

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addHeadlineFields(FormInterface $dataForm): void
    {
        $dataForm
            ->add('text', TextType::class, [
                'label' => 'label.headline_text',
                'required' => true,
            ])
            ->add('level', ChoiceType::class, [
                'label' => 'label.headline_level',
                'choices' => [
                    'label.headline_level.h1' => 'h1',
                    'label.headline_level.h2' => 'h2',
                    'label.headline_level.h3' => 'h3',
                    'label.headline_level.h4' => 'h4',
                ],
                'empty_data' => 'h2',
                'help' => 'help.page.headline_level',
            ])
            ->add('align', ChoiceType::class, [
                'label' => 'label.headline_align',
                'choices' => [
                    'label.headline_align.left' => 'left',
                    'label.headline_align.center' => 'center',
                    'label.headline_align.right' => 'right',
                ],
                'empty_data' => 'left',
            ])
            ->add('spacingBefore', ChoiceType::class, [
                'label' => 'label.headline_spacing_before',
                'choices' => [
                    'label.spacing.none' => 'none',
                    'label.spacing.sm' => 'sm',
                    'label.spacing.md' => 'md',
                    'label.spacing.lg' => 'lg',
                ],
                'empty_data' => 'none',
            ])
            ->add('spacingAfter', ChoiceType::class, [
                'label' => 'label.headline_spacing_after',
                'choices' => [
                    'label.spacing.none' => 'none',
                    'label.spacing.sm' => 'sm',
                    'label.spacing.md' => 'md',
                    'label.spacing.lg' => 'lg',
                ],
                'empty_data' => 'md',
            ])
        ;
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addHighlightFields(FormInterface $dataForm): void
    {
        $dataForm
            ->add('variant', ChoiceType::class, [
                'label' => 'label.highlight_variant',
                'choices' => [
                    'label.highlight_variant.info' => 'info',
                    'label.highlight_variant.success' => 'success',
                    'label.highlight_variant.warning' => 'warning',
                    'label.highlight_variant.danger' => 'danger',
                    'label.highlight_variant.note' => 'note',
                ],
                'empty_data' => 'info',
            ])
            ->add('title', TextType::class, [
                'label' => 'label.highlight_title',
                'required' => false,
            ])
            ->add('html', TextEditorType::class, [
                'label' => 'label.html',
                'required' => true,
                'attr' => ['rows' => 10],
                'help' => 'help.page.richtext_allowed_tags',
            ])
            ->add('iconMode', ChoiceType::class, [
                'label' => 'label.highlight_icon_mode',
                'choices' => [
                    'label.highlight_icon_mode.auto' => 'auto',
                    'label.highlight_icon_mode.custom' => 'custom',
                    'label.highlight_icon_mode.none' => 'none',
                ],
                'empty_data' => 'auto',
            ])
            ->add('icon', TextType::class, [
                'label' => 'label.highlight_icon',
                'required' => false,
                'help' => 'help.page.highlight_icon',
            ])
        ;
    }

    /**
     * @param FormInterface<array<string, mixed>> $dataForm
     */
    private function addAccordionFields(FormInterface $dataForm): void
    {
        $dataForm->add('items', CollectionType::class, [
            'label' => 'label.accordion_items',
            'entry_type' => PageContentAccordionItemType::class,
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'prototype' => true,
            'required' => true,
        ]);
    }
}
