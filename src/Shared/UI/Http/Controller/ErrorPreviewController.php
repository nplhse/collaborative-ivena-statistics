<?php

declare(strict_types=1);

namespace App\Shared\UI\Http\Controller;

use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * Renders TwigBundle error templates with the same context as {@see \Symfony\Component\HttpKernel\Controller\ErrorController}.
 * Wired from {@code config/routes/error_preview.yaml} (when@dev and when@test for automated tests).
 */
#[AsController]
final readonly class ErrorPreviewController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function __invoke(int $code): Response
    {
        if ($code < 400 || $code > 599) {
            throw new NotFoundHttpException('Invalid HTTP status code for error preview.');
        }

        $throwable = Response::HTTP_FORBIDDEN === $code
            ? new AccessDeniedException('Preview: Access denied.')
            : new HttpException($code, \sprintf('Preview HTTP %d', $code));

        // AccessDeniedException is not HttpExceptionInterface; FlattenException would default to 500 without explicit status.
        $exception = FlattenException::createFromThrowable(
            $throwable,
            $throwable instanceof HttpExceptionInterface ? null : $code,
        );

        $specific = \sprintf('@Twig/Exception/error%d.html.twig', $code);
        $template = $this->twig->getLoader()->exists($specific)
            ? $specific
            : '@Twig/Exception/error.html.twig';

        $html = $this->twig->render($template, [
            'exception' => $exception,
            'status_code' => $exception->getStatusCode(),
            'status_text' => $exception->getStatusText(),
        ]);

        return new Response($html, Response::HTTP_OK);
    }
}
