<?php

declare(strict_types=1);

namespace App\Encounter\UI\DataPrep;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Without a schema contribution there is no data preparation tool (#1301): its pages, the download and the local
 * map files answer 404 (after the firewall, so a guest still gets the login). The public update check (`latest.json`) stays, older downloaded tools still ask it.
 *
 * @psalm-suppress UnusedClass Registered via #[AsEventListener].
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class HideDataPrepWithoutContributionsListener
{
    public const array ROUTES = [
        'app_tools_data_prep',
        'app_tools_data_prep_key',
        'app_tools_data_prep_local_map_help',
        'app_tools_data_prep_local_map',
        'app_tools_data_prep_download',
    ];

    public function __construct(
        private DataPrepContributions $contributions,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (\in_array($event->getRequest()->attributes->get('_route'), self::ROUTES, true) && $this->contributions->isEmpty()) {
            throw new NotFoundHttpException('The data preparation tool has no schema.');
        }
    }
}
