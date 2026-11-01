<?php

declare(strict_types=1);

namespace App\Admin\UI\Filter;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\BooleanFilterType;
use Symfony\Contracts\Translation\TranslatableInterface;

/**
 * Yes/no filter on an unmapped property: "yes" keeps the entities whose id the provider returns,
 * "no" keeps all others. Used when the condition cannot be written in DQL, for example effective
 * roles that come from the security role hierarchy.
 */
final class IdSetFilter implements FilterInterface
{
    use FilterTrait;

    /** @var (\Closure(): list<int>)|null */
    private ?\Closure $idProvider = null;

    /**
     * @param \Closure(): list<int> $idProvider called only when the filter is applied
     */
    public static function new(string $propertyName, TranslatableInterface|string $label, \Closure $idProvider): self
    {
        $filter = new self()
            ->setFilterFqcn(self::class)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(BooleanFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle');
        $filter->idProvider = $idProvider;

        return $filter;
    }

    #[\Override]
    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $value = $filterDataDto->getValue();
        if (!\is_bool($value) || !$this->idProvider instanceof \Closure) {
            return;
        }

        $ids = ($this->idProvider)();
        $alias = $filterDataDto->getEntityAlias();
        $parameter = $filterDataDto->getParameterName();

        if ([] === $ids) {
            if ($value) {
                $queryBuilder->andWhere('1 = 0');
            }

            return;
        }

        $queryBuilder
            ->andWhere(\sprintf('%s.id %s (:%s)', $alias, $value ? 'IN' : 'NOT IN', $parameter))
            ->setParameter($parameter, $ids);
    }
}
