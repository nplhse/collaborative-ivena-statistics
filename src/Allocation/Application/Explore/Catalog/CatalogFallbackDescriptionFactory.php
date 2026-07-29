<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogCoverage;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class CatalogFallbackDescriptionFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function create(string $objectName, CatalogCoverage $coverage): string
    {
        if ($coverage->suppressed) {
            return $this->translator->trans('catalog.fallback.suppressed', [
                'name' => $objectName,
            ], 'allocation');
        }

        if (!$coverage->hasData()) {
            return $this->translator->trans('catalog.fallback.empty', [
                'name' => $objectName,
            ], 'allocation');
        }

        $params = [
            'name' => $objectName,
            'allocations' => $coverage->allocationCount,
            'hospitals' => $coverage->hospitalCount,
        ];

        if ($coverage->firstAt instanceof \DateTimeImmutable && $coverage->lastAt instanceof \DateTimeImmutable) {
            $params['from'] = $coverage->firstAt->format('Y');
            $params['to'] = $coverage->lastAt->format('Y');

            return $this->translator->trans('catalog.fallback.with_period', $params, 'allocation');
        }

        return $this->translator->trans('catalog.fallback.without_period', $params, 'allocation');
    }
}
