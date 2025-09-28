<?php

namespace App\Form;

use App\Entity\IndicationNormalized;
use App\Repository\IndicationNormalizedRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class IndicationNormalizedAutocompleteField extends AbstractType
{
    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => IndicationNormalized::class,
            'placeholder' => 'Choose a normalized Indication',
            'searchable_fields' => ['name', 'code'],
            'choice_label' => static fn (IndicationNormalized $i) => sprintf('%s — %s', $i->getName(), $i->getCode()),
            'query_builder' => function (IndicationNormalizedRepository $repo) {
                return $repo->createQueryBuilder('i')
                    ->orderBy('i.code', 'ASC')
                    ->addOrderBy('i.name', 'ASC');
            },
            'label' => 'Normalized-Indikation',
            'required' => false,
            'autocomplete' => true,
        ]);
    }

    #[\Override]
    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
