<?php

declare(strict_types=1);

namespace App\Statistics\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * ChoiceType for options whose labels are already translated in PHP.
 *
 * @extends AbstractType<mixed>
 */
final class PreTranslatedChoiceType extends AbstractType
{
    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choice_translation_domain' => false,
        ]);
    }

    #[\Override]
    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
