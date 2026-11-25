<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One link of the "Learn more" block (#1590): a path or address and an optional link text.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class PageContentReadMoreItemType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('linkUrl', TextType::class, [
                'label' => 'label.card_link_url',
                'required' => true,
                'help' => 'help.page.read_more_link_url',
            ])
            ->add('linkLabel', TextType::class, [
                'label' => 'label.card_link_label',
                'required' => false,
                'help' => 'help.page.read_more_link_label',
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'content',
        ]);
    }
}
