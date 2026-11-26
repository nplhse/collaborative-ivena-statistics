<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Translated labels for backed-enum choices in the admin (#1303, #1304). Choice keys stay the enum
 * values, so badge colour maps keyed by value keep working.
 */
final class TranslatedEnumChoices
{
    /**
     * Value => label map for `ChoiceField::setTranslatableChoices()` and `ChoiceFilter::setTranslatableChoices()`.
     *
     * @template T of \BackedEnum
     *
     * @param list<T>             $cases
     * @param \Closure(T): string $key   translation key of a case
     *
     * @return array<string, TranslatableMessage>
     */
    public static function choices(array $cases, \Closure $key, string $domain): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[(string) $case->value] = new TranslatableMessage($key($case), domain: $domain);
        }

        return $choices;
    }

    /**
     * A choice field for an enum property whose labels are translated on index, detail and form pages.
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T>     $enumClass
     * @param \Closure(T): string $key       translation key of a case
     */
    public static function field(string $property, TranslatableMessage $label, string $enumClass, \Closure $key, string $domain): ChoiceField
    {
        $cases = $enumClass::cases();
        $choices = self::choices($cases, $key, $domain);

        return ChoiceField::new($property, $label)
            ->setTranslatableChoices($choices)
            ->setFormType(EnumType::class)
            ->setFormTypeOptions([
                'class' => $enumClass,
                'choices' => $cases,
                'choice_label' => static fn (\BackedEnum $case): TranslatableMessage => $choices[(string) $case->value],
            ]);
    }
}
