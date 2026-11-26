<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security;

use App\User\Infrastructure\Security\ConfirmPasswordAuthenticator;
use PHPUnit\Framework\TestCase;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

/**
 * The password confirmation does not complete a sign-in that still waits for its second factor (#1689).
 */
final class ConfirmPasswordAuthenticatorTest extends TestCase
{
    public function testASignInWaitingForItsSecondFactorIsNotConfirmed(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(self::createStub(TwoFactorTokenInterface::class));
        $authenticator = new ConfirmPasswordAuthenticator(
            self::createStub(UrlGeneratorInterface::class),
            self::createStub(FormFactoryInterface::class),
            $tokenStorage,
        );

        $this->expectException(AuthenticationCredentialsNotFoundException::class);

        $authenticator->authenticate(Request::create('/login/confirm', Request::METHOD_POST));
    }
}
