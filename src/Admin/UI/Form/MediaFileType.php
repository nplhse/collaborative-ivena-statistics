<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * File input of the media library. The file is stored by {@see \App\Content\Infrastructure\Media\MediaUploadHandler} when
 * the media item is saved (ADR 039); the widget shows name and size of the chosen file
 * (`@Admin/form/media_file_theme.html.twig`).
 *
 * @extends AbstractType<mixed>
 */
final class MediaFileType extends AbstractType
{
    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('attr', [
            'data-file-input-preview-target' => 'input',
            'data-action' => 'change->file-input-preview#preview',
        ]);
    }

    #[\Override]
    public function getParent(): string
    {
        return FileType::class;
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'media_file';
    }
}
