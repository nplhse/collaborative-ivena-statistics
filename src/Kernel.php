<?php

namespace App;

use App\Shared\Infrastructure\DependencyInjection\AppExtension;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    #[\Override]
    public function boot(): void
    {
        parent::boot();

        date_default_timezone_set('Europe/Berlin');
    }

    #[\Override]
    protected function prepareContainer(ContainerBuilder $container): void
    {
        $container->registerExtension(new AppExtension());

        parent::prepareContainer($container);
    }
}
