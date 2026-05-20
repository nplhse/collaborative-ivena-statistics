<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Mail;

use App\Feedback\Application\RecordFeedbackHandler;
use App\Shared\Infrastructure\Mail\TransactionalMailer;
use App\User\Infrastructure\Security\EmailVerifier;
use App\User\UI\Http\Controller\ResetPasswordController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

final class TransactionalMailWiringTest extends TestCase
{
    /**
     * @param class-string $className
     */
    #[DataProvider('wiredClassProvider')]
    public function testClassUsesTransactionalMailerNotMailerInterface(string $className): void
    {
        $constructor = new \ReflectionClass($className)->getConstructor();
        self::assertNotNull($constructor);

        $parameterTypes = array_map(
            static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $constructor->getParameters(),
        );

        self::assertContains(TransactionalMailer::class, $parameterTypes);
        self::assertNotContains(MailerInterface::class, $parameterTypes);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function wiredClassProvider(): iterable
    {
        yield EmailVerifier::class => [EmailVerifier::class];
        yield ResetPasswordController::class => [ResetPasswordController::class];
        yield RecordFeedbackHandler::class => [RecordFeedbackHandler::class];
    }

    public function testResetPasswordControllerDoesNotBuildTemplatedEmailDirectly(): void
    {
        $source = (string) file_get_contents(
            new \ReflectionClass(ResetPasswordController::class)->getFileName(),
        );

        self::assertStringNotContainsString('TemplatedEmail', $source);
        self::assertStringNotContainsString('MailerInterface', $source);
    }
}
