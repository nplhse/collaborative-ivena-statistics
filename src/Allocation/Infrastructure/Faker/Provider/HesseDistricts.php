<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

/**
 * Public geography of Hesse for synthetic reference data (ADR 020).
 *
 * One entry per dispatch area the maps know: the name and geo key match
 * `config/case_flow/dispatch_area_geo_map.yaml` and the features of
 * `assets/geo/hessen-landkreise.geojson` (a test fails if they diverge).
 * Towns are real places of the district with one of their postal codes;
 * `location` says how hospitals there are classified.
 */
final class HesseDistricts
{
    public const string STATE = 'Hessen';

    /**
     * @var array<string, array{geo_key: string, towns: list<array{city: string, postal_code: string, location: 'Urban'|'Mixed'|'Rural'}>}>
     */
    public const array DISTRICTS = [
        'Bergstraße' => ['geo_key' => 'bergstrasse', 'towns' => [
            ['city' => 'Heppenheim', 'postal_code' => '64646', 'location' => 'Mixed'],
            ['city' => 'Bensheim', 'postal_code' => '64625', 'location' => 'Mixed'],
            ['city' => 'Lampertheim', 'postal_code' => '68623', 'location' => 'Mixed'],
            ['city' => 'Lindenfels', 'postal_code' => '64678', 'location' => 'Rural'],
        ]],
        'Darmstadt' => ['geo_key' => 'darmstadt', 'towns' => [
            ['city' => 'Darmstadt', 'postal_code' => '64283', 'location' => 'Urban'],
            ['city' => 'Darmstadt', 'postal_code' => '64289', 'location' => 'Urban'],
        ]],
        'Darmstadt-Dieburg' => ['geo_key' => 'darmstadt-dieburg', 'towns' => [
            ['city' => 'Dieburg', 'postal_code' => '64807', 'location' => 'Mixed'],
            ['city' => 'Groß-Umstadt', 'postal_code' => '64823', 'location' => 'Rural'],
            ['city' => 'Pfungstadt', 'postal_code' => '64319', 'location' => 'Mixed'],
            ['city' => 'Griesheim', 'postal_code' => '64347', 'location' => 'Mixed'],
        ]],
        'Frankfurt' => ['geo_key' => 'frankfurt', 'towns' => [
            ['city' => 'Frankfurt am Main', 'postal_code' => '60311', 'location' => 'Urban'],
            ['city' => 'Frankfurt am Main', 'postal_code' => '60431', 'location' => 'Urban'],
            ['city' => 'Frankfurt am Main', 'postal_code' => '65929', 'location' => 'Urban'],
        ]],
        'Fulda' => ['geo_key' => 'fulda', 'towns' => [
            ['city' => 'Fulda', 'postal_code' => '36037', 'location' => 'Urban'],
            ['city' => 'Hünfeld', 'postal_code' => '36088', 'location' => 'Rural'],
            ['city' => 'Gersfeld', 'postal_code' => '36129', 'location' => 'Rural'],
        ]],
        'Gießen' => ['geo_key' => 'giessen', 'towns' => [
            ['city' => 'Gießen', 'postal_code' => '35390', 'location' => 'Urban'],
            ['city' => 'Lich', 'postal_code' => '35423', 'location' => 'Rural'],
            ['city' => 'Grünberg', 'postal_code' => '35305', 'location' => 'Rural'],
        ]],
        'Groß-Gerau' => ['geo_key' => 'gross-gerau', 'towns' => [
            ['city' => 'Groß-Gerau', 'postal_code' => '64521', 'location' => 'Mixed'],
            ['city' => 'Rüsselsheim am Main', 'postal_code' => '65428', 'location' => 'Urban'],
            ['city' => 'Mörfelden-Walldorf', 'postal_code' => '64546', 'location' => 'Mixed'],
        ]],
        'Hersfeld-Rotenburg' => ['geo_key' => 'hersfeld-rotenburg', 'towns' => [
            ['city' => 'Bad Hersfeld', 'postal_code' => '36251', 'location' => 'Mixed'],
            ['city' => 'Rotenburg an der Fulda', 'postal_code' => '36199', 'location' => 'Rural'],
            ['city' => 'Bebra', 'postal_code' => '36179', 'location' => 'Rural'],
        ]],
        'Hochtaunus' => ['geo_key' => 'hochtaunus', 'towns' => [
            ['city' => 'Bad Homburg vor der Höhe', 'postal_code' => '61348', 'location' => 'Urban'],
            ['city' => 'Oberursel (Taunus)', 'postal_code' => '61440', 'location' => 'Mixed'],
            ['city' => 'Usingen', 'postal_code' => '61250', 'location' => 'Rural'],
            ['city' => 'Königstein im Taunus', 'postal_code' => '61462', 'location' => 'Mixed'],
        ]],
        'Kassel' => ['geo_key' => 'kassel', 'towns' => [
            ['city' => 'Kassel', 'postal_code' => '34117', 'location' => 'Urban'],
            ['city' => 'Kassel', 'postal_code' => '34125', 'location' => 'Urban'],
            ['city' => 'Hofgeismar', 'postal_code' => '34369', 'location' => 'Rural'],
            ['city' => 'Wolfhagen', 'postal_code' => '34466', 'location' => 'Rural'],
        ]],
        'Lahn-Dill' => ['geo_key' => 'lahn-dill', 'towns' => [
            ['city' => 'Wetzlar', 'postal_code' => '35578', 'location' => 'Urban'],
            ['city' => 'Dillenburg', 'postal_code' => '35683', 'location' => 'Mixed'],
            ['city' => 'Herborn', 'postal_code' => '35745', 'location' => 'Mixed'],
        ]],
        'Limburg-Weilburg' => ['geo_key' => 'limburg-weilburg', 'towns' => [
            ['city' => 'Limburg an der Lahn', 'postal_code' => '65549', 'location' => 'Mixed'],
            ['city' => 'Weilburg', 'postal_code' => '35781', 'location' => 'Rural'],
            ['city' => 'Hadamar', 'postal_code' => '65589', 'location' => 'Rural'],
        ]],
        'Main-Kinzig' => ['geo_key' => 'main-kinzig', 'towns' => [
            ['city' => 'Hanau', 'postal_code' => '63450', 'location' => 'Urban'],
            ['city' => 'Gelnhausen', 'postal_code' => '63571', 'location' => 'Mixed'],
            ['city' => 'Schlüchtern', 'postal_code' => '36381', 'location' => 'Rural'],
        ]],
        'Main-Taunus' => ['geo_key' => 'main-taunus', 'towns' => [
            ['city' => 'Hofheim am Taunus', 'postal_code' => '65719', 'location' => 'Mixed'],
            ['city' => 'Bad Soden am Taunus', 'postal_code' => '65812', 'location' => 'Mixed'],
            ['city' => 'Hochheim am Main', 'postal_code' => '65239', 'location' => 'Mixed'],
        ]],
        'Marburg-Biedenkopf' => ['geo_key' => 'marburg-biedenkopf', 'towns' => [
            ['city' => 'Marburg', 'postal_code' => '35037', 'location' => 'Urban'],
            ['city' => 'Biedenkopf', 'postal_code' => '35216', 'location' => 'Rural'],
            ['city' => 'Stadtallendorf', 'postal_code' => '35260', 'location' => 'Rural'],
        ]],
        'Odenwald' => ['geo_key' => 'odenwald', 'towns' => [
            ['city' => 'Erbach', 'postal_code' => '64711', 'location' => 'Rural'],
            ['city' => 'Michelstadt', 'postal_code' => '64720', 'location' => 'Rural'],
            ['city' => 'Bad König', 'postal_code' => '64732', 'location' => 'Rural'],
        ]],
        'Offenbach' => ['geo_key' => 'offenbach', 'towns' => [
            ['city' => 'Offenbach am Main', 'postal_code' => '63065', 'location' => 'Urban'],
            ['city' => 'Dreieich', 'postal_code' => '63303', 'location' => 'Mixed'],
            ['city' => 'Langen (Hessen)', 'postal_code' => '63225', 'location' => 'Mixed'],
            ['city' => 'Seligenstadt', 'postal_code' => '63500', 'location' => 'Mixed'],
        ]],
        'Rheingau Taunus' => ['geo_key' => 'rheingau-taunus', 'towns' => [
            ['city' => 'Bad Schwalbach', 'postal_code' => '65307', 'location' => 'Rural'],
            ['city' => 'Idstein', 'postal_code' => '65510', 'location' => 'Mixed'],
            ['city' => 'Rüdesheim am Rhein', 'postal_code' => '65385', 'location' => 'Rural'],
        ]],
        'Schwalm-Eder' => ['geo_key' => 'schwalm-eder', 'towns' => [
            ['city' => 'Homberg (Efze)', 'postal_code' => '34576', 'location' => 'Rural'],
            ['city' => 'Melsungen', 'postal_code' => '34212', 'location' => 'Rural'],
            ['city' => 'Schwalmstadt', 'postal_code' => '34613', 'location' => 'Rural'],
            ['city' => 'Fritzlar', 'postal_code' => '34560', 'location' => 'Rural'],
        ]],
        'Vogelsberg' => ['geo_key' => 'vogelsberg', 'towns' => [
            ['city' => 'Lauterbach (Hessen)', 'postal_code' => '36341', 'location' => 'Rural'],
            ['city' => 'Alsfeld', 'postal_code' => '36304', 'location' => 'Rural'],
            ['city' => 'Schotten', 'postal_code' => '63679', 'location' => 'Rural'],
        ]],
        'Waldeck-Frankenberg' => ['geo_key' => 'waldeck-frankenberg', 'towns' => [
            ['city' => 'Korbach', 'postal_code' => '34497', 'location' => 'Rural'],
            ['city' => 'Frankenberg (Eder)', 'postal_code' => '35066', 'location' => 'Rural'],
            ['city' => 'Bad Arolsen', 'postal_code' => '34454', 'location' => 'Rural'],
            ['city' => 'Bad Wildungen', 'postal_code' => '34537', 'location' => 'Rural'],
        ]],
        'Werra-Meißner' => ['geo_key' => 'werra-meissner', 'towns' => [
            ['city' => 'Eschwege', 'postal_code' => '37269', 'location' => 'Rural'],
            ['city' => 'Witzenhausen', 'postal_code' => '37213', 'location' => 'Rural'],
            ['city' => 'Hessisch Lichtenau', 'postal_code' => '37235', 'location' => 'Rural'],
        ]],
        'Wetterau' => ['geo_key' => 'wetterau', 'towns' => [
            ['city' => 'Friedberg (Hessen)', 'postal_code' => '61169', 'location' => 'Mixed'],
            ['city' => 'Bad Nauheim', 'postal_code' => '61231', 'location' => 'Mixed'],
            ['city' => 'Büdingen', 'postal_code' => '63654', 'location' => 'Rural'],
        ]],
        'Wiesbaden' => ['geo_key' => 'wiesbaden', 'towns' => [
            ['city' => 'Wiesbaden', 'postal_code' => '65183', 'location' => 'Urban'],
            ['city' => 'Wiesbaden', 'postal_code' => '65199', 'location' => 'Urban'],
        ]],
    ];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::DISTRICTS);
    }
}
