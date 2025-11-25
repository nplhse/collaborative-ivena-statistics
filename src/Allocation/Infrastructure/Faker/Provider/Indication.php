<?php

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;

/** @psalm-suppress PropertyNotSetInConstructor */
final class Indication extends Base
{
    /**
     * @var list<array{code: string, name: string}>
     */
    protected static array $indication = [
        ['code' => '721', 'name' => 'Akute Augenerkrankung'],
        ['code' => '322', 'name' => 'Synkope / Kollaps'],
        ['code' => '549', 'name' => 'Drillinge ab 33+0'],
        ['code' => '809', 'name' => 'Allgemeinmedizin, sonstiger Notfall'],
        ['code' => '323', 'name' => 'Hypotonie'],
        ['code' => '143', 'name' => 'vaECMO Abholung'],
        ['code' => '255', 'name' => 'Lähmung / Querschnitt akut (nicht Stroke)'],
        ['code' => '391', 'name' => 'Akuter endokrinologischer Notfall'],
        ['code' => '714', 'name' => 'Hämaturie'],
        ['code' => '346', 'name' => 'Kardiogener Schock'],
        ['code' => '341', 'name' => 'Myokardinfarkt ST-Hebung'],
        ['code' => '821', 'name' => 'Allergische Reaktion'],
        ['code' => '134', 'name' => 'Schock (unbekannter Genese)'],
        ['code' => '753', 'name' => 'Fremdkörper im Ohr'],
        ['code' => '212', 'name' => 'Respiratorische Globalinsuffizienz'],
        ['code' => '741', 'name' => 'Akutes Nierenversagen'],
        ['code' => '251', 'name' => 'Ischämischer Insult'],
        ['code' => '581', 'name' => 'Ertrinken'],
        ['code' => '213', 'name' => 'COPD Exazerbation'],
        ['code' => '751', 'name' => 'Fremdkörper Aspiration'],
        ['code' => '724', 'name' => 'Glaukomanfall'],
        ['code' => '311', 'name' => 'Hypertensive Krise'],
        ['code' => '711', 'name' => 'Harnverhalt'],
        ['code' => '343', 'name' => 'ACS ohne ST-Hebung'],
        ['code' => '451', 'name' => 'Polytrauma'],
        ['code' => '771', 'name' => 'Suizidversuch'],
        ['code' => '482', 'name' => 'Beckenfraktur'],
        ['code' => '331', 'name' => 'Herzrhythmusstörung Tachykard'],
        ['code' => '561', 'name' => 'Geburt Regelversorgung'],
        ['code' => '571', 'name' => 'Geburt Komplikation'],
        ['code' => '222', 'name' => 'Pneumonie'],
        ['code' => '421', 'name' => 'Thoraxtrauma'],
        ['code' => '361', 'name' => 'Lungenembolie'],
        ['code' => '813', 'name' => 'Psychiatrische Krise'],
        ['code' => '411', 'name' => 'Schädel-Hirn-Trauma'],
        ['code' => '733', 'name' => 'Akute Gastroenteritis'],
        ['code' => '132', 'name' => 'Reanimation (präklinisch erfolgreich)'],
        ['code' => '152', 'name' => 'ROSC nach AED / Laienreanimation'],
        ['code' => '731', 'name' => 'Gastrointestinale Blutung'],
        ['code' => '145', 'name' => 'prolongierte Reanimation'],
        ['code' => '131', 'name' => 'Reanimation (präklinisch, erfolglos)'],
        ['code' => '483', 'name' => 'Oberschenkelfraktur'],
        ['code' => '211', 'name' => 'Asthma bronchiale'],
        ['code' => '572', 'name' => 'Schulterdystokie'],
        ['code' => '383', 'name' => 'Hypoglykämie'],
        ['code' => '763', 'name' => 'Epileptischer Anfall'],
        ['code' => '713', 'name' => 'Hämorrhagische Zystitis'],
    ];

    /**
     * @return array{code: string, name: string}
     */
    public function indication(): array
    {
        return static::randomElement(static::$indication);
    }

    public function indicationCode(): string
    {
        $indication = $this->indication();

        return $indication['code'];
    }

    public function indicationName(): string
    {
        $indication = $this->indication();

        return $indication['name'];
    }
}
