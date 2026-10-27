<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;
use Random\Randomizer;

/** @psalm-suppress PropertyNotSetInConstructor */
final class Hospital extends Base
{
    /**
     * Common German street names; Faker's own list would draw from the global state.
     *
     * @var non-empty-list<string>
     */
    private const array STREETS = [
        'Hauptstraße', 'Bahnhofstraße', 'Schulstraße', 'Gartenstraße', 'Kirchstraße', 'Lindenstraße', 'Bergstraße',
        'Waldstraße', 'Ringstraße', 'Wiesenstraße', 'Am Markt', 'Mühlweg', 'Klinikstraße', 'Krankenhausstraße',
        'Robert-Koch-Straße', 'Goethestraße', 'Schillerstraße', 'Frankfurter Straße', 'Kasseler Straße', 'Rheinstraße',
    ];

    /** @var string[] */
    protected static $formats = [
        '{{hospitalName}}{{hospitalSuffix}}',
        '{{hospitalPrefix}} {{hospitalName}}',
        '{{hospitalName}} {{city}}',
        '{{city}}{{hospitalSuffix}}',
    ];

    /** @var non-empty-list<string> */
    protected static $hospitalName = [
        'Auguste-Viktoria', 'Anna', 'Albert-Schweitzer', 'Albertinen', 'Allgemeines', 'Annastift', 'Ammerland', 'Augustinerinnen',
        'Bethlehem', 'Bürgerhospital', 'Bernward', 'Brüderkrankenhaus', 'Bethesda', 'Bonifatius',
        'Christliches',
        'Dreifaltigkeits', 'Diakonissenanstalt',
        'Elisabeth',
        'Gesundheitszentrum', 'Grüner Kreis',
        'Hedwigshöhe', 'Hedwig', 'Hubertus', 'Heckeshorn', 'Hermann-Josef', 'Hellmig', 'Hospital zum Heiligen Geist ', 'Herz- und Gefäßzentrum', 'Heinrich-Braun',
        'Franziskus', 'Fachkrankenhaus', 'Fachklinik', 'Friederikenstift',
        'Immanuel',
        'Johanniter',
        'Krankenhaus', 'Kreiskrankenhaus', 'Katharinen', 'Kreis Krankenhaus', 'Kliniken', 'Klinikzentrum',
        'Lungenklinik', 'Luisenhospital', 'Lukas-Krankenhaus',
        'Marienkrankenhaus', 'Martin-Luther', 'Maria-Hilf', 'Marienhospital', 'Mariahilf', 'Marien Hospital',
        'Nordwest',
        'Oberhavel', 'Ostsee',
        'Prosper-Hospital',
        'Rhein-Ruhr', 'Rudolf-Virchow',
        'Unfallkrankenhaus', 'Universitätskrankenhaus',
        'Schlosspark', 'Sanatorium', 'Städtisches', 'Stiftshospital', 'Städtische Kliniken', 'St. Marien', 'Spital', 'Stadtkrankenhaus',
        'Waldkrankenhaus', 'Waldfriede', 'Weidlandkliniken',
        'Vinzenz',
    ];

    /** @var non-empty-list<string> */
    protected static $hospitalPrefix = [
        'Apollon',
        'DGR Kliniken',
        'Evangelisches',
        'Hyperion',
        'Klinikum',
        'Sankt', 'St.', 'St. Joseph', 'St. Marien', 'St. Johannes', 'St. Theresien', 'St. Vincentius', 'Spital', 'St. Vincenz',
    ];

    /** @var non-empty-list<string> */
    protected static $hospitalSuffix = [
        '-Klinikum', ' Klinikum', '-Krankenhaus',
    ];

    public function hospitalName(): string
    {
        return self::randomElement(self::$hospitalName);
    }

    public function hospitalPrefix(): string
    {
        return self::randomElement(self::$hospitalPrefix);
    }

    public function hospitalSuffix(): string
    {
        return self::randomElement(self::$hospitalSuffix);
    }

    public function hospital(): string
    {
        $format = self::randomElement(self::$formats);

        return $this->generator->parse($format);
    }

    /**
     * A complete synthetic hospital in the given dispatch area of Hesse.
     *
     * Town, postal code and coordinates fit the area; size, beds and tier fit each other,
     * and towns classified as urban get larger hospitals more often.
     *
     * @return array{
     *     name: string,
     *     tier: 'Basic'|'Extended'|'Full',
     *     size: 'Small'|'Medium'|'Large',
     *     beds: int,
     *     location: 'Urban'|'Mixed'|'Rural',
     *     address: array{street: string, postal_code: string, city: string, state: string, country: string},
     *     coordinates: array{lat: float, lng: float}
     * }
     */
    public function hospitalProfile(string $dispatchArea): array
    {
        return self::profileFor($dispatchArea, RandomDraw::fromFaker());
    }

    /**
     * The same as hospitalProfile(), drawn from the given stream (ADR 022).
     *
     * @return array{
     *     name: string,
     *     tier: 'Basic'|'Extended'|'Full',
     *     size: 'Small'|'Medium'|'Large',
     *     beds: int,
     *     location: 'Urban'|'Mixed'|'Rural',
     *     address: array{street: string, postal_code: string, city: string, state: string, country: string},
     *     coordinates: array{lat: float, lng: float}
     * }
     */
    public static function profileFor(string $dispatchArea, Randomizer $random): array
    {
        $town = Area::townFor($dispatchArea, $random);
        $size = self::weighted($random, match ($town['location']) {
            'Urban' => ['Large' => 40, 'Medium' => 40, 'Small' => 20],
            'Mixed' => ['Large' => 15, 'Medium' => 45, 'Small' => 40],
            'Rural' => ['Large' => 5, 'Medium' => 35, 'Small' => 60],
        });
        $tier = self::weighted($random, match ($size) {
            'Large' => ['Full' => 80, 'Extended' => 20],
            'Medium' => ['Extended' => 60, 'Basic' => 25, 'Full' => 15],
            'Small' => ['Basic' => 80, 'Extended' => 20],
        });
        $beds = match ($size) {
            'Large' => $random->getInt(600, 1300),
            'Medium' => $random->getInt(300, 599),
            'Small' => $random->getInt(80, 299),
        };

        $name = strtr(RandomDraw::element([
            'Klinikum {town}',
            '{name} {town}',
            '{prefix} {name} {town}',
            '{town}{suffix}',
        ], $random), [
            '{town}' => $town['city'],
            '{name}' => RandomDraw::element(self::$hospitalName, $random),
            '{prefix}' => RandomDraw::element(self::$hospitalPrefix, $random),
            '{suffix}' => RandomDraw::element(self::$hospitalSuffix, $random),
        ]);

        return [
            'name' => trim((string) preg_replace('/\s+/', ' ', $name)),
            'tier' => $tier,
            'size' => $size,
            'beds' => $beds,
            'location' => $town['location'],
            'address' => [
                'street' => sprintf('%s %d', RandomDraw::element(self::STREETS, $random), $random->getInt(1, 120)),
                'postal_code' => $town['postal_code'],
                'city' => $town['city'],
                'state' => HesseDistricts::STATE,
                'country' => 'Deutschland',
            ],
            'coordinates' => Area::coordinatesFor($dispatchArea, $random),
        ];
    }

    /**
     * @template T of string
     *
     * @param non-empty-array<T, int> $weights
     *
     * @return T
     */
    private static function weighted(Randomizer $random, array $weights): string
    {
        $roll = $random->getInt(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }
}
