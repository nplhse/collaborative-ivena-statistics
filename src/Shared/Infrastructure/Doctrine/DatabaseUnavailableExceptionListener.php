<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\ConnectionException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers an unreachable database with 503 instead of 500 (#1684), so visitors see that the service is temporarily
 * unavailable and monitoring can tell an outage from a bug. The connection error may be wrapped, for example by Twig
 * when a template reads the database, so the whole chain is searched. The original exception stays the previous one.
 *
 * @psalm-suppress UnusedClass Registered through #[AsEventListener].
 */
final class DatabaseUnavailableExceptionListener
{
    /** Before Symfony's ErrorListener logs (0) and renders (-128) the exception. */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if ($throwable instanceof HttpExceptionInterface) {
            return;
        }

        for ($cause = $throwable; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectionException) {
                $event->setThrowable(new ServiceUnavailableHttpException(null, 'The database is unreachable.', $throwable));

                return;
            }
        }
    }
}
