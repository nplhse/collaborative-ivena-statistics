<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\UI\Form;

use App\User\UI\Form\LoginType;
use App\User\UI\Form\RegistrationFormType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/**
 * The password field as the form theme `@Shared/form/password_visibility_theme.html.twig` renders it: a toggle
 * to show the password on every password field, the strength meter only where a new password is chosen
 * (`strength_feedback`, see PasswordFormTypeTest for which forms set it). Replaces the same HTML checks that the
 * functional tests of the login, registration, reset and settings pages repeated (#1348).
 */
final class PasswordWidgetRenderingTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function passwordFields(): iterable
    {
        yield 'login' => [LoginType::class, 'password', 'login_password'];
        yield 'registration' => [RegistrationFormType::class, 'plainPassword', 'registration_form_plainPassword'];
    }

    #[DataProvider('passwordFields')]
    public function testEveryPasswordFieldHasAnAccessibleToggle(string $formType, string $field, string $id): void
    {
        $html = $this->renderWidget($formType, $field);

        $input = $html->filter('input#'.$id);
        $toggle = $html->filter('[data-testid="password-toggle-'.$id.'"]');
        self::assertSame('password', $input->attr('type'));
        self::assertNull($input->attr('autocomplete'));
        self::assertSame('button', $toggle->attr('type'));
        self::assertSame('false', $toggle->attr('aria-pressed'));
        self::assertSame($id, $toggle->attr('aria-controls'));
        self::assertSame('Show password', $toggle->attr('aria-label'));
    }

    public function testANewPasswordGetsTheStrengthMeter(): void
    {
        $html = $this->renderWidget(RegistrationFormType::class, 'plainPassword');

        $wrapper = $html->filter('[data-controller]')->first();
        self::assertSame('password-visibility password-strength', $wrapper->attr('data-controller'));
        self::assertNotEmpty($wrapper->attr('data-password-strength-policy-value'));
        self::assertNotEmpty($wrapper->attr('data-password-strength-strength-labels-value'));
        self::assertSame('input->password-strength#evaluate', $html->filter('input#registration_form_plainPassword')->attr('data-action'));
        self::assertCount(1, $html->filter('[data-testid="password-strength-registration_form_plainPassword"]'));
        self::assertStringContainsString('Password strength', $html->text());
        self::assertStringContainsString('At least 8 characters', $html->text());
    }

    public function testTheLoginPasswordHasNoStrengthMeter(): void
    {
        $html = $this->renderWidget(LoginType::class, 'password');

        self::assertSame('password-visibility', $html->filter('[data-controller]')->first()->attr('data-controller'));
        self::assertCount(0, $html->filter('[data-testid="password-strength-login_password"]'));
    }

    /**
     * @param class-string $formType
     */
    private function renderWidget(string $formType, string $field): Crawler
    {
        self::bootKernel();
        $container = self::getContainer();
        // Without CSRF the form needs no session; the token is not part of the password widget anyway.
        $form = $container->get(FormFactoryInterface::class)->create($formType, null, ['csrf_protection' => false]);

        $html = $container->get(Environment::class)
            ->createTemplate('{{ form_widget(form[field]) }}')
            ->render(['form' => $form->createView(), 'field' => $field]);

        return new Crawler($html);
    }
}
