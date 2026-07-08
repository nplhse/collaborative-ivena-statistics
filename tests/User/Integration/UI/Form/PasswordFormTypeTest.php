<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\UI\Form;

use App\User\Domain\Validator\UserPasswordConstraints;
use App\User\UI\Form\ForceChangePasswordType;
use App\User\UI\Form\ResetPasswordFormType;
use App\User\UI\Form\SettingsPasswordType;
use App\User\UI\Form\UserPasswordType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormFactoryInterface;
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

    public function testResetPasswordFormConfiguresStrengthFeedbackOnFirstFieldOnly(): void
    {
        $form = $this->formFactory->create(ResetPasswordFormType::class);

        self::assertTrue($form->has('plainPassword'));
        self::assertSame('user', $form->getConfig()->getOption('translation_domain'));
        self::assertNull($form->getConfig()->getOption('data_class'));

        $first = $form->get('plainPassword')->get('first');
        $second = $form->get('plainPassword')->get('second');

        self::assertTrue($first->getConfig()->getOption('strength_feedback'));
        self::assertFalse($second->getConfig()->getOption('strength_feedback'));
        self::assertSame(UserPasswordType::class, $first->getConfig()->getType()->getInnerType()::class);

        $this->assertStrengthFeedbackView($first->createView(), enabled: true);
        $this->assertStrengthFeedbackView($second->createView(), enabled: false);
    }

    public function testForceChangePasswordFormConfiguresStrengthFeedbackOnFirstFieldOnly(): void
    {
        $form = $this->formFactory->create(ForceChangePasswordType::class);

        self::assertTrue($form->has('plainPassword'));
        self::assertSame('user', $form->getConfig()->getOption('translation_domain'));
        self::assertNull($form->getConfig()->getOption('data_class'));

        $first = $form->get('plainPassword')->get('first');
        $second = $form->get('plainPassword')->get('second');

        self::assertTrue($first->getConfig()->getOption('strength_feedback'));
        self::assertFalse($second->getConfig()->getOption('strength_feedback'));

        $this->assertStrengthFeedbackView($first->createView(), enabled: true);
        $this->assertStrengthFeedbackView($second->createView(), enabled: false);
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

    public function testUserPasswordTypeExposesPolicyConfigOnlyWhenFeedbackEnabled(): void
    {
        $enabledForm = $this->formFactory->create(UserPasswordType::class);
        $disabledForm = $this->formFactory->create(UserPasswordType::class, null, [
            'strength_feedback' => false,
        ]);

        $this->assertStrengthFeedbackView($enabledForm->createView(), enabled: true);
        $this->assertStrengthFeedbackView($disabledForm->createView(), enabled: false);
    }

    private function assertStrengthFeedbackView(FormView $view, bool $enabled): void
    {
        self::assertSame($enabled, $view->vars['strength_feedback']);

        if ($enabled) {
            self::assertSame(UserPasswordConstraints::clientConfig(), $view->vars['password_strength_policy']);

            return;
        }

        self::assertArrayNotHasKey('password_strength_policy', $view->vars);
    }
}
