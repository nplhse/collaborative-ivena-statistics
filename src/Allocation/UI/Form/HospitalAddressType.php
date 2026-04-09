<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form;

use App\Allocation\Domain\Entity\Address;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<Address>
 */
final class HospitalAddressType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('street', TextType::class, [
                'label' => 'label.address.street',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'label.address.postal_code',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('city', TextType::class, [
                'label' => 'label.address.city',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('state', TextType::class, [
                'label' => 'label.address.state',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('country', TextType::class, [
                'label' => 'label.address.country',
                'constraints' => [new Assert\NotBlank()],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Address::class,
            'translation_domain' => 'messages',
        ]);
    }
}
