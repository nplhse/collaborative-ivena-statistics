<?php

declare(strict_types=1);

namespace App;

use App\Shared\Infrastructure\DependencyInjection\AppExtension;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    public const string VERSION = 'v0.0.3-alpha';

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
        $container->setParameter('app.version', self::VERSION);
        $container->registerExtension(new AppExtension());

        parent::prepareContainer($container);
    }
}
