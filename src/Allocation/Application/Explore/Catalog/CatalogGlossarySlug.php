<?php

declare(strict_types=1);

namespace App\Allocation\Application\Explore\Catalog;

enum CatalogGlossarySlug: string
{
    case Urgency = 'urgency';
    case TransportType = 'transport-type';
    case HospitalClassifications = 'hospital-classifications';
    case ClinicalIndicators = 'clinical-indicators';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Urgency,
            self::TransportType,
            self::HospitalClassifications,
            self::ClinicalIndicators,
        ];
    }

    public function titleKey(): string
    {
        return match ($this) {
            self::Urgency => 'title.glossary.urgency',
            self::TransportType => 'title.glossary.transport_type',
            self::HospitalClassifications => 'title.glossary.hospital_classifications',
            self::ClinicalIndicators => 'title.glossary.clinical_indicators',
        };
    }

    public function introKey(): string
    {
        return match ($this) {
            self::Urgency => 'catalog.glossary.urgency.intro',
            self::TransportType => 'catalog.glossary.transport_type.intro',
            self::HospitalClassifications => 'catalog.glossary.hospital_classifications.intro',
            self::ClinicalIndicators => 'catalog.glossary.clinical_indicators.intro',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Urgency => 'tabler:alert-triangle',
            self::TransportType => 'tabler:ambulance',
            self::HospitalClassifications => 'tabler:building-hospital',
            self::ClinicalIndicators => 'tabler:heartbeat',
        };
    }
}
