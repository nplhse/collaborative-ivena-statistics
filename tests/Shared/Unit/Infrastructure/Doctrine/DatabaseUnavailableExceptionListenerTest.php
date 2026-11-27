<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\DatabaseUnavailableExceptionListener;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * An unreachable database is answered with 503, also when the error is wrapped, for example by Twig (#1684).
 */
final class DatabaseUnavailableExceptionListenerTest extends TestCase
{
    public function testAConnectionErrorBecomesServiceUnavailable(): void
    {
        $connection = $this->connectionException();

        $event = $this->handle($connection);

        self::assertInstanceOf(ServiceUnavailableHttpException::class, $event->getThrowable());
        self::assertSame(503, $event->getThrowable()->getStatusCode());
        self::assertSame($connection, $event->getThrowable()->getPrevious());
    }

    public function testAWrappedConnectionErrorBecomesServiceUnavailable(): void
    {
        $wrapped = new \RuntimeException('An exception has been thrown during the rendering of a template.', 0, $this->connectionException());

        $event = $this->handle($wrapped);

        self::assertInstanceOf(ServiceUnavailableHttpException::class, $event->getThrowable());
        self::assertSame($wrapped, $event->getThrowable()->getPrevious(), 'the original exception is kept');
    }

    public function testOtherErrorsAndHttpExceptionsStayAsTheyAre(): void
    {
        $other = new \LogicException('A bug.');
        self::assertSame($other, $this->handle($other)->getThrowable());

        $notFound = new NotFoundHttpException('Gone.', $this->connectionException());
        self::assertSame($notFound, $this->handle($notFound)->getThrowable());
    }

    private function handle(\Throwable $throwable): ExceptionEvent
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST, $throwable);
        new DatabaseUnavailableExceptionListener()->onKernelException($event);

        return $event;
    }

    private function connectionException(): ConnectionException
    {
        return new ConnectionException($this->createStub(DriverException::class), null);
    }
}
