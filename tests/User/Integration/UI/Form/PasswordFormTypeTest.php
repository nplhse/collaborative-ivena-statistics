<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\UI\Form;

use App\User\Domain\Validator\UserPasswordConstraints;
use App\User\UI\Form\ForceChangePasswordType;
use App\User\UI\Form\ResetPasswordFormType;
use App\User\UI\Form\SettingsPasswordType;
use App\User\UI\Form\UserPasswordType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormView;

final class PasswordFormTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->formFactory = self::getContainer()->get(FormFactoryInterface::class);
    }

    /**
     * @return iterable<string, array{class-string<FormTypeInterface<mixed>>}>
     */
    public static function repeatedPasswordForms(): iterable
    {
        yield 'reset password' => [ResetPasswordFormType::class];
        yield 'forced password change' => [ForceChangePasswordType::class];
    }

    /**
     * @param class-string<FormTypeInterface<mixed>> $formClass
     */
    #[DataProvider('repeatedPasswordForms')]
    public function testRepeatedPasswordFormConfiguresStrengthFeedbackOnFirstFieldOnly(string $formClass): void
    {
        $form = $this->formFactory->create($formClass);

        self::assertTrue($form->has('plainPassword'));
        self::assertSame('user', $form->getConfig()->getOption('translation_domain'));
        self::assertNull($form->getConfig()->getOption('data_class'));
        $first = $form->get('plainPassword')->get('first');
        $second = $form->get('plainPassword')->get('second');
        self::assertSame(UserPasswordType::class, $first->getConfig()->getType()->getInnerType()::class);
        self::assertSame([true, false], [$first->getConfig()->getOption('strength_feedback'), $second->getConfig()->getOption('strength_feedback')]);
        $this->assertStrengthFeedbackShown($first->createView());
        $this->assertStrengthFeedbackHidden($second->createView());
    }

    public function testSettingsPasswordFormKeepsCurrentPasswordWithoutStrengthFeedback(): void
    {
        $form = $this->formFactory->create(SettingsPasswordType::class);

        self::assertTrue($form->has('currentPassword'));
        self::assertInstanceOf(
            PasswordType::class,
            $form->get('currentPassword')->getConfig()->getType()->getInnerType(),
        );
        self::assertFalse($form->get('currentPassword')->getConfig()->hasOption('strength_feedback'));

        $first = $form->get('plainPassword')->get('first');
        $second = $form->get('plainPassword')->get('second');

        self::assertTrue($first->getConfig()->getOption('strength_feedback'));
        self::assertFalse($second->getConfig()->getOption('strength_feedback'));
    }

    public function testUserPasswordTypeExposesThePolicyByDefault(): void
    {
        $form = $this->formFactory->create(UserPasswordType::class);

        $this->assertStrengthFeedbackShown($form->createView());
    }

    public function testUserPasswordTypeHidesThePolicyWithoutFeedback(): void
    {
        $form = $this->formFactory->create(UserPasswordType::class, null, ['strength_feedback' => false]);

        $this->assertStrengthFeedbackHidden($form->createView());
    }

    private function assertStrengthFeedbackShown(FormView $view): void
    {
        self::assertTrue($view->vars['strength_feedback']);
        self::assertSame(UserPasswordConstraints::clientConfig(), $view->vars['password_strength_policy']);
    }

    private function assertStrengthFeedbackHidden(FormView $view): void
    {
        self::assertFalse($view->vars['strength_feedback']);
        self::assertArrayNotHasKey('password_strength_policy', $view->vars);
    }
}
