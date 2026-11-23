<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

/**
 * Public geography of Hesse for synthetic reference data (ADR 020).
 *
 * One entry per synthetic dispatch area, with its official districts for the demo shapes in
 * `resources/geo/demo-districts.geojson` (a test fails if they diverge).
 * Towns are real places of the district with one of their postal codes;
 * `location` says how hospitals there are classified.
 */
final class HesseDistricts
{
    public const string STATE = 'Hessen';

    /**
     * Official districts of each dispatch area; Kassel and Offenbach each cover a city and a county.
     *
     * @var array<string, list<array{ags: string, name: string, kind: 'Kreisfreie Stadt'|'Landkreis'}>>
     */
    public const array OFFICIAL_DISTRICTS = [
        'Bergstraße' => [['ags' => '06431', 'name' => 'Bergstraße', 'kind' => 'Landkreis']],
        'Darmstadt' => [['ags' => '06411', 'name' => 'Darmstadt', 'kind' => 'Kreisfreie Stadt']],
        'Darmstadt-Dieburg' => [['ags' => '06432', 'name' => 'Darmstadt-Dieburg', 'kind' => 'Landkreis']],
        'Frankfurt' => [['ags' => '06412', 'name' => 'Frankfurt am Main', 'kind' => 'Kreisfreie Stadt']],
        'Fulda' => [['ags' => '06631', 'name' => 'Fulda', 'kind' => 'Landkreis']],
        'Gießen' => [['ags' => '06531', 'name' => 'Gießen', 'kind' => 'Landkreis']],
        'Groß-Gerau' => [['ags' => '06433', 'name' => 'Groß-Gerau', 'kind' => 'Landkreis']],
        'Hersfeld-Rotenburg' => [['ags' => '06632', 'name' => 'Hersfeld-Rotenburg', 'kind' => 'Landkreis']],
        'Hochtaunus' => [['ags' => '06434', 'name' => 'Hochtaunuskreis', 'kind' => 'Landkreis']],
        'Kassel' => [
            ['ags' => '06611', 'name' => 'Kassel', 'kind' => 'Kreisfreie Stadt'],
            ['ags' => '06633', 'name' => 'Kassel', 'kind' => 'Landkreis'],
        ],
        'Lahn-Dill' => [['ags' => '06532', 'name' => 'Lahn-Dill-Kreis', 'kind' => 'Landkreis']],
        'Limburg-Weilburg' => [['ags' => '06533', 'name' => 'Limburg-Weilburg', 'kind' => 'Landkreis']],
        'Main-Kinzig' => [['ags' => '06435', 'name' => 'Main-Kinzig-Kreis', 'kind' => 'Landkreis']],
        'Main-Taunus' => [['ags' => '06436', 'name' => 'Main-Taunus-Kreis', 'kind' => 'Landkreis']],
        'Marburg-Biedenkopf' => [['ags' => '06534', 'name' => 'Marburg-Biedenkopf', 'kind' => 'Landkreis']],
        'Odenwald' => [['ags' => '06437', 'name' => 'Odenwaldkreis', 'kind' => 'Landkreis']],
        'Offenbach' => [
            ['ags' => '06413', 'name' => 'Offenbach am Main', 'kind' => 'Kreisfreie Stadt'],
            ['ags' => '06438', 'name' => 'Offenbach', 'kind' => 'Landkreis'],
        ],
        'Rheingau Taunus' => [['ags' => '06439', 'name' => 'Rheingau-Taunus-Kreis', 'kind' => 'Landkreis']],
        'Schwalm-Eder' => [['ags' => '06634', 'name' => 'Schwalm-Eder-Kreis', 'kind' => 'Landkreis']],
        'Vogelsberg' => [['ags' => '06535', 'name' => 'Vogelsbergkreis', 'kind' => 'Landkreis']],
        'Waldeck-Frankenberg' => [['ags' => '06635', 'name' => 'Waldeck-Frankenberg', 'kind' => 'Landkreis']],
        'Werra-Meißner' => [['ags' => '06636', 'name' => 'Werra-Meißner-Kreis', 'kind' => 'Landkreis']],
        'Wetterau' => [['ags' => '06440', 'name' => 'Wetteraukreis', 'kind' => 'Landkreis']],
        'Wiesbaden' => [['ags' => '06414', 'name' => 'Wiesbaden', 'kind' => 'Kreisfreie Stadt']],
    ];

    /**
     * @var array<string, array{towns: list<array{city: string, postal_code: string, location: 'Urban'|'Mixed'|'Rural'}>}>
     */
    public const array DISTRICTS = [
        'Bergstraße' => ['towns' => [
            ['city' => 'Heppenheim', 'postal_code' => '64646', 'location' => 'Mixed'],
            ['city' => 'Bensheim', 'postal_code' => '64625', 'location' => 'Mixed'],
            ['city' => 'Lampertheim', 'postal_code' => '68623', 'location' => 'Mixed'],
            ['city' => 'Lindenfels', 'postal_code' => '64678', 'location' => 'Rural'],
        ]],
        'Darmstadt' => ['towns' => [
            ['city' => 'Darmstadt', 'postal_code' => '64283', 'location' => 'Urban'],
            ['city' => 'Darmstadt', 'postal_code' => '64289', 'location' => 'Urban'],
        ]],
        'Darmstadt-Dieburg' => ['towns' => [
            ['city' => 'Dieburg', 'postal_code' => '64807', 'location' => 'Mixed'],
            ['city' => 'Groß-Umstadt', 'postal_code' => '64823', 'location' => 'Rural'],
            ['city' => 'Pfungstadt', 'postal_code' => '64319', 'location' => 'Mixed'],
            ['city' => 'Griesheim', 'postal_code' => '64347', 'location' => 'Mixed'],
        ]],
        'Frankfurt' => ['towns' => [
            ['city' => 'Frankfurt am Main', 'postal_code' => '60311', 'location' => 'Urban'],
            ['city' => 'Frankfurt am Main', 'postal_code' => '60431', 'location' => 'Urban'],
            ['city' => 'Frankfurt am Main', 'postal_code' => '65929', 'location' => 'Urban'],
        ]],
        'Fulda' => ['towns' => [
            ['city' => 'Fulda', 'postal_code' => '36037', 'location' => 'Urban'],
            ['city' => 'Hünfeld', 'postal_code' => '36088', 'location' => 'Rural'],
            ['city' => 'Gersfeld', 'postal_code' => '36129', 'location' => 'Rural'],
        ]],
        'Gießen' => ['towns' => [
            ['city' => 'Gießen', 'postal_code' => '35390', 'location' => 'Urban'],
            ['city' => 'Lich', 'postal_code' => '35423', 'location' => 'Rural'],
            ['city' => 'Grünberg', 'postal_code' => '35305', 'location' => 'Rural'],
        ]],
        'Groß-Gerau' => ['towns' => [
            ['city' => 'Groß-Gerau', 'postal_code' => '64521', 'location' => 'Mixed'],
            ['city' => 'Rüsselsheim am Main', 'postal_code' => '65428', 'location' => 'Urban'],
            ['city' => 'Mörfelden-Walldorf', 'postal_code' => '64546', 'location' => 'Mixed'],
        ]],
        'Hersfeld-Rotenburg' => ['towns' => [
            ['city' => 'Bad Hersfeld', 'postal_code' => '36251', 'location' => 'Mixed'],
            ['city' => 'Rotenburg an der Fulda', 'postal_code' => '36199', 'location' => 'Rural'],
            ['city' => 'Bebra', 'postal_code' => '36179', 'location' => 'Rural'],
        ]],
        'Hochtaunus' => ['towns' => [
            ['city' => 'Bad Homburg vor der Höhe', 'postal_code' => '61348', 'location' => 'Urban'],
            ['city' => 'Oberursel (Taunus)', 'postal_code' => '61440', 'location' => 'Mixed'],
            ['city' => 'Usingen', 'postal_code' => '61250', 'location' => 'Rural'],
            ['city' => 'Königstein im Taunus', 'postal_code' => '61462', 'location' => 'Mixed'],
        ]],
        'Kassel' => ['towns' => [
            ['city' => 'Kassel', 'postal_code' => '34117', 'location' => 'Urban'],
            ['city' => 'Kassel', 'postal_code' => '34125', 'location' => 'Urban'],
            ['city' => 'Hofgeismar', 'postal_code' => '34369', 'location' => 'Rural'],
            ['city' => 'Wolfhagen', 'postal_code' => '34466', 'location' => 'Rural'],
        ]],
        'Lahn-Dill' => ['towns' => [
            ['city' => 'Wetzlar', 'postal_code' => '35578', 'location' => 'Urban'],
            ['city' => 'Dillenburg', 'postal_code' => '35683', 'location' => 'Mixed'],
            ['city' => 'Herborn', 'postal_code' => '35745', 'location' => 'Mixed'],
        ]],
        'Limburg-Weilburg' => ['towns' => [
            ['city' => 'Limburg an der Lahn', 'postal_code' => '65549', 'location' => 'Mixed'],
            ['city' => 'Weilburg', 'postal_code' => '35781', 'location' => 'Rural'],
            ['city' => 'Hadamar', 'postal_code' => '65589', 'location' => 'Rural'],
        ]],
        'Main-Kinzig' => ['towns' => [
            ['city' => 'Hanau', 'postal_code' => '63450', 'location' => 'Urban'],
            ['city' => 'Gelnhausen', 'postal_code' => '63571', 'location' => 'Mixed'],
            ['city' => 'Schlüchtern', 'postal_code' => '36381', 'location' => 'Rural'],
        ]],
        'Main-Taunus' => ['towns' => [
            ['city' => 'Hofheim am Taunus', 'postal_code' => '65719', 'location' => 'Mixed'],
            ['city' => 'Bad Soden am Taunus', 'postal_code' => '65812', 'location' => 'Mixed'],
            ['city' => 'Hochheim am Main', 'postal_code' => '65239', 'location' => 'Mixed'],
        ]],
        'Marburg-Biedenkopf' => ['towns' => [
            ['city' => 'Marburg', 'postal_code' => '35037', 'location' => 'Urban'],
            ['city' => 'Biedenkopf', 'postal_code' => '35216', 'location' => 'Rural'],
            ['city' => 'Stadtallendorf', 'postal_code' => '35260', 'location' => 'Rural'],
        ]],
        'Odenwald' => ['towns' => [
            ['city' => 'Erbach', 'postal_code' => '64711', 'location' => 'Rural'],
            ['city' => 'Michelstadt', 'postal_code' => '64720', 'location' => 'Rural'],
            ['city' => 'Bad König', 'postal_code' => '64732', 'location' => 'Rural'],
        ]],
        'Offenbach' => ['towns' => [
            ['city' => 'Offenbach am Main', 'postal_code' => '63065', 'location' => 'Urban'],
            ['city' => 'Dreieich', 'postal_code' => '63303', 'location' => 'Mixed'],
            ['city' => 'Langen (Hessen)', 'postal_code' => '63225', 'location' => 'Mixed'],
            ['city' => 'Seligenstadt', 'postal_code' => '63500', 'location' => 'Mixed'],
        ]],
        'Rheingau Taunus' => ['towns' => [
            ['city' => 'Bad Schwalbach', 'postal_code' => '65307', 'location' => 'Rural'],
            ['city' => 'Idstein', 'postal_code' => '65510', 'location' => 'Mixed'],
            ['city' => 'Rüdesheim am Rhein', 'postal_code' => '65385', 'location' => 'Rural'],
        ]],
        'Schwalm-Eder' => ['towns' => [
            ['city' => 'Homberg (Efze)', 'postal_code' => '34576', 'location' => 'Rural'],
            ['city' => 'Melsungen', 'postal_code' => '34212', 'location' => 'Rural'],
            ['city' => 'Schwalmstadt', 'postal_code' => '34613', 'location' => 'Rural'],
            ['city' => 'Fritzlar', 'postal_code' => '34560', 'location' => 'Rural'],
        ]],
        'Vogelsberg' => ['towns' => [
            ['city' => 'Lauterbach (Hessen)', 'postal_code' => '36341', 'location' => 'Rural'],
            ['city' => 'Alsfeld', 'postal_code' => '36304', 'location' => 'Rural'],
            ['city' => 'Schotten', 'postal_code' => '63679', 'location' => 'Rural'],
        ]],
        'Waldeck-Frankenberg' => ['towns' => [
            ['city' => 'Korbach', 'postal_code' => '34497', 'location' => 'Rural'],
            ['city' => 'Frankenberg (Eder)', 'postal_code' => '35066', 'location' => 'Rural'],
            ['city' => 'Bad Arolsen', 'postal_code' => '34454', 'location' => 'Rural'],
            ['city' => 'Bad Wildungen', 'postal_code' => '34537', 'location' => 'Rural'],
        ]],
        'Werra-Meißner' => ['towns' => [
            ['city' => 'Eschwege', 'postal_code' => '37269', 'location' => 'Rural'],
            ['city' => 'Witzenhausen', 'postal_code' => '37213', 'location' => 'Rural'],
            ['city' => 'Hessisch Lichtenau', 'postal_code' => '37235', 'location' => 'Rural'],
        ]],
        'Wetterau' => ['towns' => [
            ['city' => 'Friedberg (Hessen)', 'postal_code' => '61169', 'location' => 'Mixed'],
            ['city' => 'Bad Nauheim', 'postal_code' => '61231', 'location' => 'Mixed'],
            ['city' => 'Büdingen', 'postal_code' => '63654', 'location' => 'Rural'],
        ]],
        'Wiesbaden' => ['towns' => [
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
