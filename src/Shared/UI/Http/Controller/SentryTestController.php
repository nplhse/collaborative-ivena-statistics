<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SentryTestController extends AbstractController
{
    #[Route('/_debug/sentry/test', name: 'app_debug_sentry_test', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!\in_array($this->getParameter('kernel.environment'), ['dev', 'staging'], true)) {
            throw new NotFoundHttpException();
        }

        throw new \RuntimeException('Sentry test exception from collaborative-ivena-statistics.');
    }
}
