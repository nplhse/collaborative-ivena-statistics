<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use EasyCorp\Bundle\EasyAdminBundle\Form\Type\TextEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One card of the card block (#1578): optional icon, title, short text and optional link.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class PageContentCardItemType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('icon', TextType::class, [
                'label' => 'label.card_icon',
                'required' => false,
                'help' => 'help.page.highlight_icon',
            ])
            ->add('title', TextType::class, [
                'label' => 'label.card_title',
                'required' => true,
            ])
            ->add('html', TextEditorType::class, [
                'label' => 'label.html',
                'required' => true,
                'attr' => ['rows' => 6],
                'help' => 'help.page.richtext_allowed_tags',
            ])
            ->add('linkUrl', TextType::class, [
                'label' => 'label.card_link_url',
                'required' => false,
                'help' => 'help.page.card_link_url',
            ])
            ->add('linkLabel', TextType::class, [
                'label' => 'label.card_link_label',
                'required' => false,
                'help' => 'help.page.card_link_label',
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
