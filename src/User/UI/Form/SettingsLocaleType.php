<?php

declare(strict_types=1);

namespace App\User\UI\Form;

use App\Shared\Application\Locale\SupportedLocales;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class SettingsLocaleType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $automaticDefaultLocale */
        $automaticDefaultLocale = $options['automatic_default_locale'];

        $builder->add('locale', ChoiceType::class, [
            'choices' => [
                SupportedLocales::DEFAULT => SupportedLocales::DEFAULT,
                SupportedLocales::GERMAN => SupportedLocales::GERMAN,
            ],
            'choice_label' => fn (string $locale): string => $this->buildChoiceLabel($locale, $automaticDefaultLocale),
            'constraints' => [
                new NotBlank(),
                new Choice(choices: SupportedLocales::ALL),
            ],
            'label' => false,
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'automatic_default_locale' => SupportedLocales::DEFAULT,
        ]);

        $resolver->setAllowedValues('automatic_default_locale', SupportedLocales::ALL);
    }

    private function buildChoiceLabel(string $locale, string $automaticDefaultLocale): string
    {
        $label = $this->translator->trans(match ($locale) {
            SupportedLocales::GERMAN => 'locale.german',
            default => 'locale.english',
        });

        if ($locale !== $automaticDefaultLocale) {
            return $label;
        }

        return $this->translator->trans('label.settings.language.automatic_default', [
            'language' => $label,
        ]);
    }
}
