<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Application\ReferenceCatalog\Package\AliasedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\AliasEntry;
use App\Allocation\Application\ReferenceCatalog\Package\DispatchAreaEntry;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalAddress;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalCoordinates;
use App\Allocation\Application\ReferenceCatalog\Package\HospitalEntry;
use App\Allocation\Application\ReferenceCatalog\Package\IndicationGroupEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NamedEntry;
use App\Allocation\Application\ReferenceCatalog\Package\NormalizedIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\PackageChecksum;
use App\Allocation\Application\ReferenceCatalog\Package\PackageMeta;
use App\Allocation\Application\ReferenceCatalog\Package\PackageSource;
use App\Allocation\Application\ReferenceCatalog\Package\RawIndicationEntry;
use App\Allocation\Application\ReferenceCatalog\Package\RawIndicationReview;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogFormat;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogNaturalKey;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackage;
use App\Allocation\Application\ReferenceCatalog\Package\ReferenceCatalogPackageWriter;
use App\Allocation\Application\ReferenceCatalog\Package\StateEntry;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Infrastructure\Faker\Provider\Assignment;
use App\Allocation\Infrastructure\Faker\Provider\HesseDistricts;
use App\Allocation\Infrastructure\Faker\Provider\Hospital;
use App\Allocation\Infrastructure\Faker\Provider\Indication;
use App\Allocation\Infrastructure\Faker\Provider\Infection;
use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\Allocation\Infrastructure\Faker\Provider\Occasion;
use App\Allocation\Infrastructure\Faker\Provider\PzcCodes;
use App\Allocation\Infrastructure\Faker\Provider\SecondaryTransport;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * Builds a complete synthetic reference catalog from the Faker providers (ADR 020, decision 9).
 *
 * The same seed and size give a byte-identical package: ids are UUIDv5 of type and natural key,
 * the emit order is fixed and the export time is constant. Values are drawn from a local
 * Randomizer, not from PHP's global random state. The package goes through the same validation
 * and import as a real one.
 */
final readonly class SyntheticReferenceCatalogBuilder
{
    public const int DEFAULT_SEED = 740;

    public const int DEFAULT_HOSPITALS = 60;

    /** Namespace of the synthetic public ids; never change it, ids would change with it. */
    private const string ID_NAMESPACE = '5f0e3c1a-7d42-4b8e-9c6a-2e1f0d4b7a90';

    private const string REVIEWED_AT = '2026-01-01T00:00:00+00:00';

    /**
     * Groups of normalized indications by code prefix; members are exact ids.
     *
     * @var list<array{name: string, prefixes: list<string>, description: string}>
     */
    private const array GROUPS = [
        ['name' => 'Reanimation', 'prefixes' => ['12', '13'], 'description' => 'Reanimation with and without ROSC'],
        ['name' => 'ECMO / eCPR', 'prefixes' => ['14'], 'description' => 'Extracorporeal procedures'],
        ['name' => 'Mehrfachverletzung', 'prefixes' => ['21'], 'description' => 'Polytrauma and multiple injuries'],
        ['name' => 'Akutes Koronarsyndrom', 'prefixes' => ['33'], 'description' => 'Chest pain, STEMI and NSTEMI'],
        ['name' => 'Vergiftung', 'prefixes' => ['36', '370'], 'description' => 'Poisoning'],
        ['name' => 'Schlaganfall', 'prefixes' => ['42'], 'description' => 'Stroke and thrombectomy'],
        ['name' => 'Geburtshilfe', 'prefixes' => ['52', '53', '54'], 'description' => 'Pregnancy and birth'],
    ];

    public function __construct(
        private ReferenceCatalogPackageWriter $writer,
    ) {
    }

    public function build(int $seed = self::DEFAULT_SEED, int $hospitals = self::DEFAULT_HOSPITALS): ReferenceCatalogPackage
    {
        // One local stream: other code in the process cannot reach it, and it is reseeded on every build.
        $random = new Randomizer(new Mt19937($seed));

        $stateId = $this->id(ReferenceCatalogType::State, HesseDistricts::STATE);
        $areas = [];
        foreach (HesseDistricts::names() as $name) {
            $areas[$name] = new DispatchAreaEntry($this->id(ReferenceCatalogType::DispatchArea, $stateId.'|'.$name), $name, $stateId);
        }

        $normalized = [];
        foreach (PzcCodes::all() as $indication) {
            $normalized[$indication['code']] = new NormalizedIndicationEntry(
                $this->id(ReferenceCatalogType::IndicationNormalized, $indication['code'].'|'.$indication['name']),
                $indication['code'],
                $indication['name'],
            );
        }

        $package = new ReferenceCatalogPackage(
            new PackageMeta(
                ReferenceCatalogFormat::NAME,
                ReferenceCatalogFormat::VERSION,
                self::REVIEWED_AT,
                true,
                ReferenceCatalogType::values(),
                [],
                new PackageChecksum(ReferenceCatalogFormat::CHECKSUM_ALGORITHM, ''),
                null,
                'synthetic',
                [new PackageSource(PzcCodes::SOURCE, PzcCodes::VERSION, PzcCodes::DATE)],
                sprintf('Synthetic reference catalog, seed %d, %d hospitals.', $seed, $hospitals),
            ),
            [new StateEntry($stateId, HesseDistricts::STATE)],
            array_values($areas),
            $this->aliased(ReferenceCatalogType::Department, MedicalSpecialities::unitNames(), $random),
            $this->aliased(ReferenceCatalogType::Speciality, MedicalSpecialities::fieldNames(), $random, everyNth: 0),
            $this->named(ReferenceCatalogType::Assignment, Assignment::names()),
            $this->named(ReferenceCatalogType::Occasion, Occasion::names()),
            $this->named(ReferenceCatalogType::Infection, Infection::names()),
            $this->named(ReferenceCatalogType::SecondaryTransport, SecondaryTransport::names()),
            array_values($normalized),
            $this->rawIndications($normalized, $random),
            $this->groups($normalized),
            $this->hospitals($hospitals, $stateId, $areas, $random),
        );

        return $this->writer->seal($package);
    }

    /**
     * @param list<string> $names
     *
     * @return list<NamedEntry>
     */
    private function named(ReferenceCatalogType $type, array $names): array
    {
        return array_map(fn (string $name): NamedEntry => new NamedEntry($this->id($type, $name), $name), $names);
    }

    /**
     * Every n-th entry gets a generated alias (0 = none).
     *
     * @param list<string> $names
     *
     * @return list<AliasedEntry>
     */
    private function aliased(ReferenceCatalogType $type, array $names, Randomizer $random, int $everyNth = 4): array
    {
        $canonical = array_flip(array_map(ReferenceCatalogNaturalKey::name(...), $names));
        $entries = [];
        foreach ($names as $index => $name) {
            $aliases = [];
            if ($everyNth > 0 && 0 === $index % $everyNth) {
                $alias = MedicalSpecialities::aliasFor($name, $random);
                if (!isset($canonical[ReferenceCatalogNaturalKey::name($alias['name'])])) {
                    $aliases[] = new AliasEntry($alias['name'], $alias['classification'], 'synthetic');
                }
            }

            $entries[] = new AliasedEntry($this->id($type, ReferenceCatalogNaturalKey::name($name)), $name, $aliases);
        }

        return $entries;
    }

    /**
     * One raw label with the official wording per code, a generated variant for every third code,
     * and a few labels nobody mapped.
     *
     * @param array<string, NormalizedIndicationEntry> $normalized
     *
     * @return list<RawIndicationEntry>
     */
    private function rawIndications(array $normalized, Randomizer $random): array
    {
        $raw = [];
        $index = 0;
        foreach ($normalized as $code => $entry) {
            $this->addRaw($raw, (string) $code, $entry->name, $entry->id);
            if (0 === $index++ % 3) {
                $this->addRaw($raw, (string) $code, Indication::rawLabelFor((string) $code, $random), $entry->id);
            }
        }

        for ($i = 0; $i < 12; ++$i) {
            $unmapped = Indication::unmappedFor($random);
            $this->addRaw($raw, $unmapped['code'], $unmapped['name'], null);
        }

        return array_values($raw);
    }

    /**
     * @param array<string, RawIndicationEntry> $raw
     */
    private function addRaw(array &$raw, string $code, string $name, ?string $target): void
    {
        $key = ReferenceCatalogNaturalKey::rawIndication($code, $name);
        if (isset($raw[$key])) {
            return;
        }

        $raw[$key] = new RawIndicationEntry(
            $this->id(ReferenceCatalogType::IndicationRaw, $key),
            $code,
            $name,
            null === $target
                ? new RawIndicationReview('unreviewed')
                : new RawIndicationReview('matched', null, self::REVIEWED_AT),
            $target,
            null === $target ? null : self::REVIEWED_AT,
        );
    }

    /**
     * @param array<string, NormalizedIndicationEntry> $normalized
     *
     * @return list<IndicationGroupEntry>
     */
    private function groups(array $normalized): array
    {
        $groups = [];
        foreach (self::GROUPS as $order => $group) {
            $members = [];
            foreach ($normalized as $code => $entry) {
                foreach ($group['prefixes'] as $prefix) {
                    if (str_starts_with((string) $code, $prefix)) {
                        $members[] = $entry->id;
                    }
                }
            }
            sort($members);

            $category = PzcCodes::category(str_pad($group['prefixes'][0], 3, '0'));
            $groups[] = new IndicationGroupEntry(
                $this->id(ReferenceCatalogType::IndicationGroup, ReferenceCatalogNaturalKey::name($group['name'])),
                $group['name'],
                $members,
                $category,
                $group['description'],
                $order + 1,
            );
        }

        return $groups;
    }

    /**
     * Spread over all dispatch areas, so every area has at least one hospital once there are 24.
     *
     * @param array<string, DispatchAreaEntry> $areas
     *
     * @return list<HospitalEntry>
     */
    private function hospitals(int $count, string $stateId, array $areas, Randomizer $random): array
    {
        $names = array_keys($areas);
        $hospitals = [];
        for ($i = 0; $i < $count; ++$i) {
            $area = $names[$i % \count($names)];

            $profile = Hospital::profileFor($area, $random);
            for ($attempt = 0; isset($hospitals[ReferenceCatalogNaturalKey::name($profile['name'])]) && $attempt < 10; ++$attempt) {
                $profile = Hospital::profileFor($area, $random);
            }
            $key = ReferenceCatalogNaturalKey::name($profile['name']);
            if (isset($hospitals[$key])) {
                $profile['name'] .= sprintf(' (%s)', $area);
                $key = ReferenceCatalogNaturalKey::name($profile['name']);
            }

            $address = $profile['address'];
            $hospitals[$key] = new HospitalEntry(
                $this->id(ReferenceCatalogType::Hospital, $key),
                $profile['name'],
                $stateId,
                $areas[$area]->id,
                $profile['size'],
                $profile['beds'],
                $profile['location'],
                new HospitalAddress($address['street'], $address['postal_code'], $address['city'], $address['state'], $address['country']),
                $profile['tier'],
                new HospitalCoordinates($profile['coordinates']['lat'], $profile['coordinates']['lng']),
            );
        }

        return array_values($hospitals);
    }

    private function id(ReferenceCatalogType $type, string $naturalKey): string
    {
        return Uuid::v5(Uuid::fromString(self::ID_NAMESPACE), $type->value.'|'.$naturalKey)->toRfc4122();
    }
}
