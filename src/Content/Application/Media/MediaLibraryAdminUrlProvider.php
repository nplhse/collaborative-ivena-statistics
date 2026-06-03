<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

final readonly class MediaLibraryAdminUrlProvider
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function getIndexUrl(): string
    {
        return $this->adminUrlGenerator
            ->setController(MediaCrudController::class)
            ->setAction(Crud::PAGE_INDEX)
            ->generateUrl();
    }
}
