<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;

/** @psalm-suppress PropertyNotSetInConstructor */
final class Hospital extends Base
{
    /** @var string[] */
    protected static $formats = [
        '{{hospitalName}}{{hospitalSuffix}}',
        '{{hospitalPrefix}} {{hospitalName}}',
        '{{hospitalName}} {{city}}',
        '{{city}}{{hospitalSuffix}}',
    ];

    /** @var string[] */
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

    /** @var string[] */
    protected static $hospitalPrefix = [
        'Apollon',
        'DGR Kliniken',
        'Evangelisches',
        'Hyperion',
        'Klinikum',
        'Sankt', 'St.', 'St. Joseph', 'St. Marien', 'St. Johannes', 'St. Theresien', 'St. Vincentius', 'Spital', 'St. Vincenz',
    ];

    /** @var string[] */
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
        $town = Area::townIn($dispatchArea);
        $size = $this->weighted(match ($town['location']) {
            'Urban' => ['Large' => 40, 'Medium' => 40, 'Small' => 20],
            'Mixed' => ['Large' => 15, 'Medium' => 45, 'Small' => 40],
            'Rural' => ['Large' => 5, 'Medium' => 35, 'Small' => 60],
        });
        $tier = $this->weighted(match ($size) {
            'Large' => ['Full' => 80, 'Extended' => 20],
            'Medium' => ['Extended' => 60, 'Basic' => 25, 'Full' => 15],
            'Small' => ['Basic' => 80, 'Extended' => 20],
        });
        $beds = match ($size) {
            'Large' => self::numberBetween(600, 1300),
            'Medium' => self::numberBetween(300, 599),
            'Small' => self::numberBetween(80, 299),
        };

        $name = strtr(self::randomElement([
            'Klinikum {town}',
            '{name} {town}',
            '{prefix} {name} {town}',
            '{town}{suffix}',
        ]), [
            '{town}' => $town['city'],
            '{name}' => $this->hospitalName(),
            '{prefix}' => $this->hospitalPrefix(),
            '{suffix}' => $this->hospitalSuffix(),
        ]);

        return [
            'name' => trim((string) preg_replace('/\s+/', ' ', $name)),
            'tier' => $tier,
            'size' => $size,
            'beds' => $beds,
            'location' => $town['location'],
            'address' => [
                'street' => sprintf('%s %d', $this->generator->format('streetName'), self::numberBetween(1, 120)),
                'postal_code' => $town['postal_code'],
                'city' => $town['city'],
                'state' => HesseDistricts::STATE,
                'country' => 'Deutschland',
            ],
            'coordinates' => Area::coordinatesIn($dispatchArea),
        ];
    }

    /**
     * @template T of string
     *
     * @param non-empty-array<T, int> $weights
     *
     * @return T
     */
    private function weighted(array $weights): string
    {
        $roll = self::numberBetween(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }
}
