<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\DataFixtures\Pattern\Dto\DistributionPattern;
use App\DataFixtures\Pattern\Dto\PatternSegment;
use App\DataFixtures\Pattern\Infrastructure\Export\AllocationStatsPatternQuery;
use App\DataFixtures\Pattern\Infrastructure\PatternYamlSerializer;

final readonly class PatternExporter
{
    public function __construct(
        private AllocationStatsPatternQuery $query,
        private PatternYamlSerializer $serializer,
    ) {
    }

    /**
     * @return list<string> exported pattern names
     */
    public function exportAll(int $minSampleSize = 100): array
    {
        try {
            $existing = $this->serializer->loadManifest();
            $patterns = \is_array($existing['patterns'] ?? null) ? $existing['patterns'] : [];
        } catch (\RuntimeException) {
            $patterns = [];
        }

        $manifest = [
            'version' => 1,
            'patterns' => $patterns,
        ];

        $exported = [];
        foreach ($this->segments() as $name => $segment) {
            $sampleSize = $this->query->countRows($segment->hospitalTier, $segment->hospitalLocation);
            if ($sampleSize < $minSampleSize) {
                continue;
            }

            $filename = $name.'.yaml';
            $pattern = new DistributionPattern(
                name: $name,
                version: 1,
                schema: DistributionPattern::SCHEMA,
                segment: $segment,
                meta: [
                    'sample_size' => $sampleSize,
                    'exported_at' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
                    'source' => 'anonymized_aggregate',
                ],
                distributions: $this->query->exportDistributions($segment->hospitalTier, $segment->hospitalLocation),
            );

            $this->serializer->savePattern($filename, $pattern);
            $manifest['patterns'][$name] = [
                'file' => $filename,
                'segment' => $segment->toArray(),
            ];
            $exported[] = $name;
        }

        $this->serializer->saveManifest($manifest);

        return $exported;
    }

    /**
     * @return array<string, PatternSegment>
     */
    private function segments(): array
    {
        return [
            'urban-full' => new PatternSegment(HospitalTier::FULL, HospitalLocation::URBAN),
            'urban-extended' => new PatternSegment(HospitalTier::EXTENDED, HospitalLocation::URBAN),
            'urban-basic' => new PatternSegment(HospitalTier::BASIC, HospitalLocation::URBAN),
            'mixed-full' => new PatternSegment(HospitalTier::FULL, HospitalLocation::MIXED),
            'rural-basic' => new PatternSegment(HospitalTier::BASIC, HospitalLocation::RURAL),
        ];
    }
}
