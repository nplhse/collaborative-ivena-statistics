<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use App\User\Domain\Validator\UserPasswordConstraints;
use App\User\Infrastructure\Registration\RegistrationIdentityGuard;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class RegistrationFormType extends AbstractType
{
    public function __construct(
        private readonly RegistrationIdentityGuard $registrationIdentityChecker,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'label.username',
                'translation_domain' => 'messages',
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 3, max: 180),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'label.email_address',
                'translation_domain' => 'user',
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                ],
            ])
            ->add('plainPassword', UserPasswordType::class, [
                'mapped' => false,
                'label' => 'label.password',
                'translation_domain' => 'user',
                'constraints' => UserPasswordConstraints::forPlainPassword(),
            ])
            ->add('acceptTerms', CheckboxType::class, [
                'mapped' => false,
                'label' => false,
                'constraints' => [
                    new IsTrue(message: 'validation.registration.accept_terms_required'),
                ],
            ]);

        // After FormValidatorListener (priority 0): only check uniqueness when field constraints passed.
        $builder->addEventListener(
            FormEvents::POST_SUBMIT,
            function (FormEvent $event): void {
                $this->assertIdentityAvailable($event);
            },
            -10,
        );
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'user',
            'data_class' => null,
        ]);
    }

    private function assertIdentityAvailable(FormEvent $event): void
    {
        $form = $event->getForm();
        if (!$form->isRoot() || \count($form->getErrors(true)) > 0) {
            return;
        }

        /** @var array{username?: mixed, email?: mixed} $data */
        $data = $form->getData();
        $username = $data['username'] ?? null;
        $email = $data['email'] ?? null;
        if (!\is_string($username) || !\is_string($email)) {
            return;
        }

        if ($this->registrationIdentityChecker->isIdentityTaken($username, $email)) {
            $form->addError($this->registrationIdentityChecker->createIdentityTakenFormError());
        }
    }
}
