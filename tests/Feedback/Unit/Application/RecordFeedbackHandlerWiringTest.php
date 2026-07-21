<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Application;

use App\Feedback\Application\Contract\AdminFeedbackNotifierInterface;
use App\Feedback\Application\RecordFeedbackHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class RecordFeedbackHandlerWiringTest extends TestCase
{
    public function testHandlerUsesAdminFeedbackNotifierNotMailerInterface(): void
    {
        $constructor = new \ReflectionClass(RecordFeedbackHandler::class)->getConstructor();
        self::assertNotNull($constructor);

        $parameterTypes = array_map(
            static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $constructor->getParameters(),
        );

        self::assertContains(AdminFeedbackNotifierInterface::class, $parameterTypes);
        self::assertNotContains(MailerInterface::class, $parameterTypes);
    }
}
