<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\Monitoring;

use Sentry\ClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class SentryIgnoredExceptionsTest extends KernelTestCase
{
    public function testIgnoreExceptionsIncludesExpectedHttpOutcomes(): void
    {
        self::bootKernel();

        $client = self::getContainer()->get(ClientInterface::class);
        $ignored = $client->getOptions()->getIgnoreExceptions();

        self::assertContains(NotFoundHttpException::class, $ignored);
        self::assertContains(AccessDeniedHttpException::class, $ignored);
        self::assertContains(AccessDeniedException::class, $ignored);
        self::assertContains(FatalError::class, $ignored);
    }
}
