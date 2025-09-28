<?php

namespace App\Form;

use App\Entity\IndicationNormalized;
use App\Repository\IndicationNormalizedRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

final class IndicationRawAssignType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', IntegerType::class, [
                'label' => 'Raw-Code',
                'disabled' => true,
            ])
            ->add('name', TextType::class, [
                'label' => 'Raw-Name',
                'disabled' => true,
            ])
            ->add('target', ChoiceType::class, [
                'choices' => [
                    'Choose a portion size' => '',
                    'small' => 's',
                    'medium' => 'm',
                    'large' => 'l',
                    'extra large' => 'xl',
                    'all you can eat' => '∞',
                ],
                'autocomplete' => true,
            ]);
    }
}
