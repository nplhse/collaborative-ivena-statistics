<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\UI\Http\Controller\Media\MediaCrudController;
use App\Content\Application\Contract\MediaLibraryAdminUrlProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** @psalm-suppress UnusedClass Wired via #[AsAlias] for MediaLibraryAdminUrlProviderInterface. */
#[AsAlias(MediaLibraryAdminUrlProviderInterface::class)]
final readonly class EasyAdminMediaLibraryUrlProvider implements MediaLibraryAdminUrlProviderInterface
{
    public function __construct(
        private AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    #[\Override]
    public function getIndexUrl(): string
    {
        return $this->adminUrlGenerator
            ->setController(MediaCrudController::class)
            ->setAction(Crud::PAGE_INDEX)
            ->generateUrl();
    }
}
