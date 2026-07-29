<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

use App\Allocation\Application\DTO\CatalogGlossaryPage;
use App\Allocation\Application\DTO\CatalogGlossarySection;
use App\Allocation\Application\DTO\CatalogGlossaryTerm;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Statistics\Application\Mapping\ClinicalIndicatorDefinitions;

final readonly class CatalogGlossaryFactory
{
    public function create(CatalogGlossarySlug $slug): CatalogGlossaryPage
    {
        return match ($slug) {
            CatalogGlossarySlug::Urgency => $this->urgency(),
            CatalogGlossarySlug::TransportType => $this->transportType(),
            CatalogGlossarySlug::HospitalClassifications => $this->hospitalClassifications(),
            CatalogGlossarySlug::ClinicalIndicators => $this->clinicalIndicators(),
        };
    }

    /**
     * @return list<array{slug: CatalogGlossarySlug, titleKey: string, introKey: string, icon: string}>
     */
    public function indexEntries(): array
    {
        return array_map(
            static fn (CatalogGlossarySlug $slug): array => [
                'slug' => $slug,
                'titleKey' => $slug->titleKey(),
                'introKey' => $slug->introKey(),
                'icon' => $slug->icon(),
            ],
            CatalogGlossarySlug::ordered(),
        );
    }

    private function urgency(): CatalogGlossaryPage
    {
        $terms = [];
        foreach (AllocationUrgency::cases() as $urgency) {
            $terms[] = new CatalogGlossaryTerm(
                code: $urgency->skLabel(),
                labelKey: $urgency->label(),
                labelDomain: 'messages',
                descriptionKey: 'catalog.glossary.urgency.'.strtolower($urgency->name).'.description',
                filterRoute: 'app_explore_allocation_list',
                filterParams: ['urgency' => (string) $urgency->value],
            );
        }

        return new CatalogGlossaryPage(
            CatalogGlossarySlug::Urgency,
            [new CatalogGlossarySection('catalog.glossary.section.values', $terms)],
        );
    }

    private function transportType(): CatalogGlossaryPage
    {
        $terms = [];
        foreach (AllocationTransportType::cases() as $type) {
            $terms[] = new CatalogGlossaryTerm(
                code: $type->value,
                labelKey: $type->label(),
                labelDomain: 'messages',
                descriptionKey: 'catalog.glossary.transport_type.'.strtolower($type->name).'.description',
                filterRoute: 'app_explore_allocation_list',
                filterParams: ['transportType' => $type->value],
            );
        }

        return new CatalogGlossaryPage(
            CatalogGlossarySlug::TransportType,
            [new CatalogGlossarySection('catalog.glossary.section.values', $terms)],
        );
    }

    private function hospitalClassifications(): CatalogGlossaryPage
    {
        $tierTerms = [];
        foreach (HospitalTier::cases() as $tier) {
            $tierTerms[] = new CatalogGlossaryTerm(
                code: $tier->value,
                labelKey: 'hospital.tier.'.$tier->value,
                labelDomain: 'allocation',
                descriptionKey: 'catalog.glossary.hospital.tier.'.strtolower($tier->name).'.description',
                filterRoute: 'app_explore_hospital_list',
                filterParams: ['tier' => $tier->value],
                actionLabelKey: 'catalog.action.view_hospitals',
            );
        }

        $sizeTerms = [];
        foreach (HospitalSize::cases() as $size) {
            $sizeTerms[] = new CatalogGlossaryTerm(
                code: $size->value,
                labelKey: 'hospital.size.'.$size->value,
                labelDomain: 'allocation',
                descriptionKey: 'catalog.glossary.hospital.size.'.strtolower($size->name).'.description',
                filterRoute: 'app_explore_hospital_list',
                filterParams: ['size' => $size->value],
                actionLabelKey: 'catalog.action.view_hospitals',
            );
        }

        $locationTerms = [];
        foreach (HospitalLocation::cases() as $location) {
            $locationTerms[] = new CatalogGlossaryTerm(
                code: $location->value,
                labelKey: 'hospital.location.'.$location->value,
                labelDomain: 'allocation',
                descriptionKey: 'catalog.glossary.hospital.location.'.strtolower($location->name).'.description',
                filterRoute: 'app_explore_hospital_list',
                filterParams: ['location' => $location->value],
                actionLabelKey: 'catalog.action.view_hospitals',
            );
        }

        return new CatalogGlossaryPage(
            CatalogGlossarySlug::HospitalClassifications,
            [
                new CatalogGlossarySection('catalog.glossary.section.hospital_tier', $tierTerms),
                new CatalogGlossarySection('catalog.glossary.section.hospital_size', $sizeTerms),
                new CatalogGlossarySection('catalog.glossary.section.hospital_location', $locationTerms),
            ],
        );
    }

    private function clinicalIndicators(): CatalogGlossaryPage
    {
        $resourceTerms = [];
        foreach (ClinicalIndicatorDefinitions::forDimension(ClinicalIndicatorDefinitions::DIMENSION_RESOURCES) as $definition) {
            $resourceTerms[] = $this->clinicalTerm($definition->bucketKey, $definition->labelTranslationKey);
        }

        $featureTerms = [];
        foreach (ClinicalIndicatorDefinitions::forDimension(ClinicalIndicatorDefinitions::DIMENSION_FEATURES) as $definition) {
            $featureTerms[] = $this->clinicalTerm($definition->bucketKey, $definition->labelTranslationKey);
        }

        return new CatalogGlossaryPage(
            CatalogGlossarySlug::ClinicalIndicators,
            [
                new CatalogGlossarySection('catalog.glossary.section.clinical_resources', $resourceTerms),
                new CatalogGlossarySection('catalog.glossary.section.clinical_features', $featureTerms),
            ],
        );
    }

    private function clinicalTerm(string $bucketKey, string $labelKey): CatalogGlossaryTerm
    {
        $labelDomain = str_starts_with($labelKey, 'field.') ? 'messages' : 'statistics';
        $filter = $this->clinicalFilter($bucketKey);

        return new CatalogGlossaryTerm(
            code: $bucketKey,
            labelKey: $labelKey,
            labelDomain: $labelDomain,
            descriptionKey: 'catalog.glossary.clinical.'.$bucketKey.'.description',
            filterRoute: $filter['route'] ?? null,
            filterParams: $filter['params'] ?? [],
        );
    }

    /**
     * @return array{route?: string, params?: array<string, scalar>}
     */
    private function clinicalFilter(string $bucketKey): array
    {
        return match ($bucketKey) {
            'resus' => ['route' => 'app_explore_allocation_list', 'params' => ['requiresResus' => 1]],
            'cathlab' => ['route' => 'app_explore_allocation_list', 'params' => ['requiresCathlab' => 1]],
            'cpr' => ['route' => 'app_explore_allocation_list', 'params' => ['isCPR' => 1]],
            'ventilation' => ['route' => 'app_explore_allocation_list', 'params' => ['isVentilated' => 1]],
            'shock' => ['route' => 'app_explore_allocation_list', 'params' => ['isShock' => 1]],
            'pregnancy' => ['route' => 'app_explore_allocation_list', 'params' => ['isPregnant' => 1]],
            'work_accident' => ['route' => 'app_explore_allocation_list', 'params' => ['isWorkAccident' => 1]],
            'infection' => ['route' => 'app_explore_allocation_list', 'params' => ['isInfectious' => 1]],
            default => [],
        };
    }
}
