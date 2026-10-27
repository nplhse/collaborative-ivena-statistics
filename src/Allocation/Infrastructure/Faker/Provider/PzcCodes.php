<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\Faker\Provider;

/**
 * The official PZC list, used as publicly available reference data (ADR 020, decision 6).
 *
 * Source: "Patientenzuweisungscode und Rückmeldeindikation", version 1.3.11 of 13.06.2025,
 * erarbeitet durch AG PZC bundesweit, freigegeben durch IVENA Anwenderbeirat HE,
 * distributed through the IVENA web portal. All 208 codes with their official wording;
 * the footnote marker of 332 is left out. Updating to a newer version means updating this list.
 */
final class PzcCodes
{
    public const string SOURCE = 'PZC';

    public const string VERSION = '1.3.11';

    public const string DATE = '2025-06-13';

    /** @var array<int, string> category per first digit of the code */
    public const array CATEGORIES = [
        '0' => 'Kein Patient',
        '1' => 'Reanimation / ECMO',
        '2' => 'Verletzungen / Unfälle',
        '3' => 'Erkrankungen – Innere',
        '4' => 'Neurologie / Psychiatrie',
        '5' => 'Pädiatrie / Gynäkologie / Geburtshilfe',
        '6' => 'Logistik / Transporte',
        '7' => 'Erkrankungen – Fachgebiete',
        '8' => 'Allgemeinmedizin',
    ];

    /** @var array<int|string, string> code → official wording (PHP turns most codes into int keys; use all() or name()) */
    public const array CODES = [
        '000' => 'Kein Patient vorhanden',
        '111' => 'primäre Todesfeststellung',
        '121' => 'Reanimation ohne ROSC',
        '122' => 'Reanimation mit ROSC (Tod am EO)',
        '123' => 'Reanimation mit ROSC (Tod auf Trsp.)',
        '131' => 'Reanimation laufend /intermittierend',
        '132' => 'Reanimation ROSC',
        '133' => 'Reanimation bei Trauma',
        '134' => 'Reanimation Hypothermie',
        '140' => 'vvECMO Zuverlegung',
        '141' => 'vvECMO Abholung',
        '142' => 'vaECMO Zuverlegung',
        '143' => 'vaECMO Abholung',
        '144' => 'eCPR Zuverlegung',
        '145' => 'eCPR Abholung',
        '201' => 'KST-Chirurgie Zu-Verlegung Intensiv mit Arzt',
        '202' => 'KST-Chirurgie Zu-Verlegung Intensiv ohne Arzt',
        '203' => 'KST-Chirurgie Zu-Verlegung IMC mit Arzt',
        '204' => 'KST-Chirurgie Zu-Verlegung IMC ohne Arzt',
        '205' => 'KST-Neurochirurgie Zu-Verlegung Intensiv mit Arzt',
        '206' => 'KST-Neurochirurgie Zu-Verlegung Intensiv ohne Arzt',
        '211' => 'Polytrauma mit SHT',
        '212' => 'Polytrauma ohne SHT',
        '213' => 'Schockraumindikation nach Unfallhergang',
        '214' => 'Mehrfachverletzung sonstige',
        '215' => 'Mehrfachverletzung mit Augen',
        '219' => 'oberflächliche Verletzung beliebiger Lokalisation',
        '221' => 'SHT offen',
        '222' => 'SHT geschlossen',
        '223' => 'Gesichtsverletzung',
        '224' => 'Kopfverletzung',
        '225' => 'Augenverletzung',
        '231' => 'Thorax penetrierend',
        '232' => 'Thorax geschlossen/stumpf',
        '233' => 'Pneumothorax (traumatisch)',
        '241' => 'Abdomen penetrierend',
        '242' => 'Abdomen geschlossen/stumpf',
        '243' => 'Akutes Abdomen (nicht traumatisch)',
        '251' => 'Verletzung der Wirbelsäulen mit neuro. Ausfällen',
        '252' => 'Verletzung der Wirbelsäulen ohne neuro. Ausfällen',
        '253' => 'Rückenschmerzen, akut mit neuro. Symptomatik',
        '254' => 'Rückenschmerzen, nicht traumatisch ohne neuro. Ausfällen',
        '255' => 'Lähmung / Querschnitt akut (nicht Stroke)',
        '261' => 'Becken offen',
        '262' => 'Becken geschlossen',
        '263' => 'Urogenitaltrauma (isoliert)',
        '271' => 'Extremitäten offen',
        '272' => 'Extremitäten geschlossen',
        '273' => 'Hüft-/Schenkelhalsfraktur',
        '274' => 'Verl. d. Extrem. mit Gefäß-/Nervenverl.',
        '275' => 'Handverletzung',
        '276' => 'Finger Amputation',
        '277' => 'Hand-/Extremitäten Amputation',
        '281' => 'Verbrennung / Verbrühung',
        '282' => 'Verätzung',
        '283' => 'Hochspannungstrauma',
        '284' => 'Barotrauma / Tauchunfall',
        '285' => 'Strahlentrauma',
        '286' => 'Hitzeerschöpfung / Hitzschlag',
        '287' => 'Unterkühlung / Erfrierung',
        '288' => '(Beinahe-) Ertrinken / Badeunfall',
        '291' => 'Aortenaneurysma (abdominal)',
        '292' => 'Extremitätenischaemie (akut)',
        '293' => 'Aorta ascendens Dissektion (bestätigt)',
        '299' => 'Gefäßchirurgischer Notfall, sonstiger',
        '301' => 'KST-Innere Medizin Zu-Verlegung Intensiv mit Arzt',
        '302' => 'KST-Innere Medizin Zu-Verlegung Intensiv ohne Arzt',
        '303' => 'KST-Innere Medizin Zu-Verlegung IMC mit Arzt',
        '304' => 'KST-Innere Medizin Zu-Verlegung IMC ohne Arzt',
        '311' => 'Atemnot (unklar) /Atembeschwerden /ARI',
        '312' => 'Obstruktion (Asthma / COPD)',
        '313' => 'Hämoptoe / Hämoptysen',
        '314' => '(Bolus-) Aspiration',
        '315' => 'Bronchitis / Pneumonie',
        '316' => 'Hyperventilation',
        '317' => 'Lungenödem (nicht kardial)',
        '318' => 'Spontanpneumothorax',
        '319' => 'Pneumologischer Notfall, sonstiger',
        '321' => 'Anaphylaxie / Unverträglichkeitsreaktion',
        '322' => 'Synkope / Kollaps',
        '323' => 'Hypotonie',
        '324' => 'Thrombose',
        '325' => 'Unklares Fieber',
        '326' => 'Exsikkose',
        '329' => 'Internistischer Notfall, sonstiger',
        '331' => 'Unklarer Brust-/Thoraxschmerz',
        '332' => 'STEMI/“OMI“',
        '333' => 'NSTEMI, instabile AP',
        '341' => 'Arrhythmie',
        '342' => 'Bradykardie',
        '343' => 'Tachykardie',
        '344' => 'Elektrounfall (Schwachstrom)',
        '345' => 'Hypertensiver Notfall/Krise',
        '346' => 'Kardiogener Schock',
        '347' => 'Herzinsuffizienz (DKZ 1 = kardiales Lungenödem)',
        '348' => 'Lungenembolie',
        '349' => 'Kardiologischer Notfall, sonstiger',
        '351' => 'GI-Blutung',
        '353' => 'Bauchschmerzen',
        '354' => 'Gastroenteritis',
        '359' => 'Gastroenterologischer Notfall, sonstiger',
        '360' => 'Rauchgas/Reizgas (nicht CO)',
        '361' => 'Alkohol',
        '362' => 'Drogen / Rauschgift',
        '363' => 'Mischintoxikation / Alkohol / Drogen / Medikamente',
        '364' => 'Lebensmittel',
        '365' => 'Medikamente',
        '366' => 'Pflanzenschutzmittel',
        '367' => 'Tierische Gifte',
        '368' => 'Giftpflanzen',
        '369' => 'Inhalative Intoxikation, sonstige',
        '370' => 'Kohlenmonoxid - Vergiftung',
        '371' => 'Meningitis /Enzephalitis',
        '372' => 'TBC',
        '373' => 'Sepsis (Infekt. + qSOFA mind. 2)',
        '374' => 'Septischer Schock',
        '375' => 'Hochkontagiöse Erkrankung (Sonder-ISO)',
        '376' => 'Infektionskrankheit – (bestätigt)',
        '377' => 'Infektionskrankheit – (nicht bestätigt)',
        '378' => 'Infektionskrankheit – (zur Quarantäne)',
        '379' => 'Infektiologischer Notfall - sonstiger',
        '391' => 'Akuter endokrinologischer Notfall',
        '392' => 'Hyperglykämie',
        '393' => 'Hypoglykämie',
        '401' => 'KST-Neurologie Zu-Verlegung Intensiv mit Arzt',
        '402' => 'KST-Neurologie Zu-Verlegung Intensiv ohne Arzt',
        '411' => 'Anhaltender epileptischer Krampfanfall',
        '412' => 'Epileptischer Anfall (stattgehabt)',
        '413' => 'Kopf-/Gesichtsschmerz (bei DKZ 1 --> Neurochirurgie)',
        '414' => 'Vigilanzminderung /Koma (ohne Trauma)',
        '415' => 'Schwindel',
        '419' => 'Neurologischer Notfall, sonstiger',
        '421' => 'Schlaganfall / Blutung < 24 h oder unklar',
        '422' => 'Wie 421 von Einsatzstelle --> Thrombektomie',
        '423' => 'Schlaganfall / Blutung >24 h',
        '425' => 'diagnostizierter Cerebraler Gefäßverschluss zur Thrombektomie',
        '431' => 'Suizid, angedroht',
        '432' => 'Einweisung, psychiatrische',
        '433' => 'Einweisung (nach LandesPsychKG)',
        '434' => 'Einweisung (nach LandesPsychKG); fixiert',
        '435' => 'Akute Verwirrtheit/Delir',
        '439' => 'Psychiatrischer Notfall, sonstiger',
        '501' => 'KST-Kinderheilkunde Zu-Verlegung Intensiv mit Arzt',
        '502' => 'KST-Kinderheilkunde Zu-Verlegung Intensiv ohne Arzt',
        '503' => 'KST-Zuverlegung Inkubator',
        '511' => 'Pädiatrisch - Atemnot',
        '512' => 'schwerer Husten (Pseudokrupp)',
        '513' => 'pädiatrisch Fieberkrampf',
        '519' => 'Pädiatrischer Notfall, sonstiger',
        '521' => '<16 SSW',
        '522' => '16+0 SSW bis 21+6 SSW',
        '523' => '22+0 SSW bis 28+6 SSW',
        '524' => '29+0 SSW bis 31+6 SSW',
        '525' => '32+0 SSW bis 36+6 SSW + jede Wachstumsstörung',
        '526' => 'ab 37+0 SSW',
        '527' => 'ab 37+0 SSW + Diabetes',
        '528' => 'Drillinge bis 32+6, alle über 3 Mehrlinge',
        '529' => 'Drillinge ab 33+0',
        '530' => 'akute fetale Gefährdung, Erstversorgung ggf. in ungeeigneter Klinik',
        '531' => '<16 SSW',
        '532' => '16+0 SSW bis 21+6 SSW',
        '533' => '22+0 SSW bis 28+6 SSW',
        '534' => '29+0 SSW bis 31+6 SSW',
        '535' => '32+0 SSW bis 36+6 SSW + jede Wachstumsstörung',
        '536' => 'ab 37+0 SSW',
        '537' => 'ab 37+0 SSW + Diabetes',
        '538' => 'Drillinge bis 32+6, alle über 3 Mehrlinge',
        '539' => 'Drillinge ab 33+0',
        '540' => 'akute fetale Gefährdung, Erstversorgung ggf. in ungeeigneter Klinik',
        '541' => '<16 SSW',
        '542' => '16+0 SSW bis 21+6 SSW',
        '543' => '22+0 SSW bis 28+6 SSW',
        '544' => '29+0 SSW bis 31+6 SSW',
        '545' => '32+0 SSW bis 36+6 SSW + jede Wachstumsstörung',
        '546' => 'ab 37+0 SSW',
        '547' => 'ab 37+0 SSW + Diabetes',
        '548' => 'Drillinge bis 32+6, alle über 3 Mehrlinge',
        '549' => 'Drillinge ab 33+0',
        '551' => 'Vaginale Blutung',
        '552' => 'Unterbauchschmerzen',
        '553' => 'Sexualdelikt',
        '559' => 'Gynäkologischer Notfall, sonstiger',
        '601' => 'Transport zu definierten Leistungen',
        '608' => 'Tragehilfe',
        '626' => 'KST-Strategische Verlegung (z.B. Kleeblatt)',
        '631' => 'Entlassung / Rücktransport',
        '701' => 'Haut-u. Geschlechtskrankheiten',
        '711' => 'Nieren- Harnleiterkolik',
        '712' => 'Hodenschmerz',
        '713' => 'Harnverhalt (akut)',
        '714' => 'Hämaturie',
        '715' => 'Katheterwechsel (transurethral)',
        '716' => 'Katheterwechsel (suprapubisch)',
        '717' => 'Katheterverlust/-verstopfung',
        '718' => 'Harnwegsinfekt (DKZ 1 = Urosepsis)',
        '719' => 'Urologischer Notfall, sonstiger',
        '721' => 'Akute Augenerkrankung',
        '729' => 'Augennotfall, sonstiger',
        '731' => 'Nasenbluten (Epistaxis) unstillbar',
        '732' => '(Nach-)Blutung, HNO, akut',
        '733' => 'Tracheostoma-Komplikation',
        '739' => 'HNO Notfall, sonstiger',
        '749' => 'MKG Notfall, sonstiger',
        '751' => 'Geriatrische Einweisung',
        '779' => 'sonstige Notfallsituationen',
        '801' => 'Schmerz/Schwellung Bewegungsapparat',
        '802' => 'Schwellung/Abszeß sonstige Lokalisation',
        '809' => 'Allgemeinmedizin, sonstiger Notfall',
    ];

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function all(): array
    {
        $all = [];
        foreach (self::CODES as $code => $name) {
            $all[] = ['code' => str_pad((string) $code, 3, '0', \STR_PAD_LEFT), 'name' => $name];
        }

        return $all;
    }

    public static function name(string $code): string
    {
        return self::CODES[$code] ?? throw new \InvalidArgumentException(sprintf('"%s" is not a PZC code.', $code));
    }

    public static function category(string $code): string
    {
        if (1 !== preg_match('/^\d{3}$/', $code)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a PZC code.', $code));
        }

        return self::CATEGORIES[(int) $code[0]] ?? throw new \InvalidArgumentException(sprintf('"%s" is not a PZC code.', $code));
    }
}
