<?php

namespace App\Service\Seed;

use App\Entity\IndicationNormalized;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<array{code:string, name:string}>
 */
#[AsTaggedItem('app.seed_provider')]
final class IndicationNormalizedSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<IndicationNormalized>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        foreach ($this->provide() as $row) {
            $entity = new IndicationNormalized()
                ->setName($row['name'])
                ->setCode((int) $row['code'])
                ->setCreatedBy($user);

            yield $entity;
        }
    }

    /**
     * @return \Generator<int, array{code:string, name:string}, mixed, void>
     */
    #[\Override]
    public function provide(): \Generator
    {
        yield ['code' => '000', 'name' => 'Kein Patient vorhanden'];
        yield ['code' => '111', 'name' => 'primäre Todesfeststellung'];
        yield ['code' => '121', 'name' => 'Reanimation ohne ROSC'];
        yield ['code' => '122', 'name' => 'Reanimation mit ROSC (Tod am EO)'];
        yield ['code' => '123', 'name' => 'Reanimation mit ROSC (Tod auf Trsp.)'];
        yield ['code' => '131', 'name' => 'Reanimation laufend /intermittierend'];
        yield ['code' => '132', 'name' => 'Reanimatino ROSC'];
        yield ['code' => '133', 'name' => 'Reanimation bei Trauma laufend/intermittierend'];
        yield ['code' => '134', 'name' => 'Reanimation Hypothermie'];
        yield ['code' => '140', 'name' => 'vvECMO Zuverlegung'];
        yield ['code' => '141', 'name' => 'vvECMO Abholung'];
        yield ['code' => '142', 'name' => 'vaECMO Zuverlegung'];
        yield ['code' => '143', 'name' => 'vaECMO Abholung'];
        yield ['code' => '144', 'name' => 'eCPR Zuverlegung'];
        yield ['code' => '145', 'name' => 'eCPR Abholung'];
        yield ['code' => '201', 'name' => 'Chirurgie Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '202', 'name' => 'Chirurgie Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '203', 'name' => 'Chirurgie Zu-Verlegung IMC mit Arzt'];
        yield ['code' => '204', 'name' => 'Chirurgie Zu-Verlegung IMC ohne Arzt'];
        yield ['code' => '205', 'name' => 'Neurochirurgie Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '206', 'name' => 'Neurochirurgie Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '211', 'name' => 'Polytrauma mit SHT'];
        yield ['code' => '212', 'name' => 'Polytrauma ohne SHT'];
        yield ['code' => '213', 'name' => 'Schockraumindikation nach Unfallhergang'];
        yield ['code' => '214', 'name' => 'Mehrfachverletzung sonstige'];
        yield ['code' => '215', 'name' => 'Mehrfachverletzung mit Augen'];
        yield ['code' => '219', 'name' => 'oberflächliche Verletzung beliebiger Lokalisation'];
        yield ['code' => '211', 'name' => 'SHT offen'];
        yield ['code' => '222', 'name' => 'SHT geschlossen'];
        yield ['code' => '223', 'name' => 'Gesichtsverletzung'];
        yield ['code' => '224', 'name' => 'Kopfverletzung'];
        yield ['code' => '225', 'name' => 'Augenverletzung'];
        yield ['code' => '231', 'name' => 'Thorax penetrierend'];
        yield ['code' => '232', 'name' => 'Thorax geschlossen/stumpf'];
        yield ['code' => '233', 'name' => 'Pneumothorax (traumatisch)'];
        yield ['code' => '241', 'name' => 'Abdomen penetrierend'];
        yield ['code' => '242', 'name' => 'Abdomen geschlossen/stumpf'];
        yield ['code' => '243', 'name' => 'Akutes Abdomen (nicht traumatisch)'];
        yield ['code' => '251', 'name' => 'Verletzung der Wirbelsäulen mit neuro. Ausfällen'];
        yield ['code' => '252', 'name' => 'Verletzung der Wirbelsäulen ohne neuro. Ausfällen'];
        yield ['code' => '253', 'name' => 'Rückenschmerzen, akut mit neuro. Symptomatik'];
        yield ['code' => '254', 'name' => 'Rückenschmerzen, nicht traumatisch ohne neuro. Ausfällen'];
        yield ['code' => '255', 'name' => 'Lähmung / Querschnitt akut (nicht Stroke)'];
        yield ['code' => '261', 'name' => 'Becken offen'];
        yield ['code' => '262', 'name' => 'Becken geschlossen'];
        yield ['code' => '263', 'name' => 'Urogenitaltrauma (isoliert)'];
        yield ['code' => '271', 'name' => 'Extremitäten offen'];
        yield ['code' => '272', 'name' => 'Extremitäten geschlossen'];
        yield ['code' => '273', 'name' => 'Hüft-/Schenkelhalsfraktur'];
        yield ['code' => '274', 'name' => 'Verl. d. Extrem. mit Gefäß-/Nervenverl.'];
        yield ['code' => '275', 'name' => 'Handverletzung'];
        yield ['code' => '276', 'name' => 'Finger Amputation'];
        yield ['code' => '277', 'name' => 'Hand-/Extremitäten Amputation'];
        yield ['code' => '281', 'name' => 'Verbrennung / Verbrühung'];
        yield ['code' => '282', 'name' => 'Verätzung'];
        yield ['code' => '283', 'name' => 'Hochspannungstrauma'];
        yield ['code' => '284', 'name' => 'Barotrauma / Tauchunfall /Dekompressionskrankheit'];
        yield ['code' => '285', 'name' => 'Strahlentrauma'];
        yield ['code' => '286', 'name' => 'Hitzeerschöpfung / Hitzschlag'];
        yield ['code' => '287', 'name' => 'Unterkühlung / Erfrierung'];
        yield ['code' => '288', 'name' => '(Beinahe-) Ertrinken / Badeunfall'];
        yield ['code' => '291', 'name' => 'Aortenaneurysma'];
        yield ['code' => '292', 'name' => 'Extremitätenischaemie (akut)'];
        yield ['code' => '293', 'name' => 'Aorta ascendens Dissektion (bestätigt)'];
        yield ['code' => '299', 'name' => 'Gefäßchirurgischer Notfall, sonstiger'];
        yield ['code' => '301', 'name' => 'Innere Medizin Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '302', 'name' => 'Innere Medizin Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '303', 'name' => 'Innere Medizin Zu-Verlegung IMC mit Arzt'];
        yield ['code' => '304', 'name' => 'Innere Medizin Zu-Verlegung IMC ohne Arzt'];
        yield ['code' => '311', 'name' => 'Atemnot (unklar) /Atembeschwerden /ARI'];
        yield ['code' => '312', 'name' => 'Obstruktion (Asthma / COPD)'];
        yield ['code' => '313', 'name' => 'Hämoptoe / Hämoptysen'];
        yield ['code' => '314', 'name' => '(Bolus-) Aspiration'];
        yield ['code' => '315', 'name' => 'Bronchitis / Pneumonie'];
        yield ['code' => '316', 'name' => 'Hyperventilation'];
        yield ['code' => '317', 'name' => 'Lungenödem (nicht kardial)'];
        yield ['code' => '318', 'name' => 'Spontanpneumothorax'];
        yield ['code' => '319', 'name' => 'Pneumologischer Notfall, sonstiger'];
        yield ['code' => '321', 'name' => 'Anaphylaxie / Unverträglichkeitsreaktion'];
        yield ['code' => '322', 'name' => 'Synkope / Kollaps'];
        yield ['code' => '323', 'name' => 'Hypotonie'];
        yield ['code' => '324', 'name' => 'Thrombose'];
        yield ['code' => '325', 'name' => 'Unklares Fieber'];
        yield ['code' => '326', 'name' => 'Exsikkose'];
        yield ['code' => '329', 'name' => 'Internistischer Notfall, sonstiger'];
        yield ['code' => '331', 'name' => 'Unklarer Brust-/Thoraxschmerz'];
        yield ['code' => '332', 'name' => 'STEMI/“OMI“'];
        yield ['code' => '333', 'name' => 'NSTEMI, instabile AP'];
        yield ['code' => '341', 'name' => 'Arrhythmie'];
        yield ['code' => '342', 'name' => 'Bradykardie'];
        yield ['code' => '343', 'name' => 'Tachykardie'];
        yield ['code' => '344', 'name' => 'Elektrounfall (Schwachstrom)'];
        yield ['code' => '345', 'name' => 'Hypertensiver Notfall/Krise'];
        yield ['code' => '346', 'name' => 'Kardiogener Schock'];
        yield ['code' => '347', 'name' => 'Herzinsuffizienz'];
        yield ['code' => '348', 'name' => 'Lungenembolie'];
        yield ['code' => '349', 'name' => 'Kardiologischer Notfall, sonstiger'];
        yield ['code' => '351', 'name' => 'GI-Blutung'];
        yield ['code' => '353', 'name' => 'Bauchschmerzen'];
        yield ['code' => '354', 'name' => 'Gastroenteritis'];
        yield ['code' => '359', 'name' => 'Gastroenterologischer Notfall, sonstiger'];
        yield ['code' => '360', 'name' => 'Rauchgas/Reizgas (nicht CO)'];
        yield ['code' => '361', 'name' => 'Alkohol'];
        yield ['code' => '362', 'name' => 'Drogen / Rauschgift'];
        yield ['code' => '363', 'name' => 'Mischintoxikation / Alkohol / Drogen / Medikamente'];
        yield ['code' => '364', 'name' => 'Lebensmittel'];
        yield ['code' => '365', 'name' => 'Medikamente'];
        yield ['code' => '366', 'name' => 'Pflanzenschutzmittel'];
        yield ['code' => '367', 'name' => 'Tierische Gifte'];
        yield ['code' => '368', 'name' => 'Giftpflanzen'];
        yield ['code' => '369', 'name' => 'Inhalative Intoxikation, sonstige'];
        yield ['code' => '370', 'name' => 'Kohlenmonoxid-Vergiftung'];
        yield ['code' => '371', 'name' => 'Meningitis /Enzephalitis'];
        yield ['code' => '372', 'name' => 'TBC'];
        yield ['code' => '373', 'name' => 'Sepsis (Infekt. + qSOFA mind. 2)'];
        yield ['code' => '374', 'name' => 'Septischer Schock'];
        yield ['code' => '375', 'name' => 'Hochkontagiöse Erkrankung (Sonder-ISO)'];
        yield ['code' => '376', 'name' => 'Infektionskrankheit – (bestätigt)'];
        yield ['code' => '377', 'name' => 'Infektionskrankheit – (nicht bestätigt)'];
        yield ['code' => '378', 'name' => 'Infektionskrankheit – (zur Quarantäne)'];
        yield ['code' => '379', 'name' => 'Infektiologischer Notfall, sonstiger'];
        yield ['code' => '391', 'name' => 'Akuter endokrinologischer Notfall'];
        yield ['code' => '392', 'name' => 'Hyperglykämie'];
        yield ['code' => '393', 'name' => 'Hypoglykämie'];
        yield ['code' => '401', 'name' => 'Neurologie Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '402', 'name' => 'Neurologie Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '411', 'name' => 'Anhaltender epileptischer Krampfanfall'];
        yield ['code' => '412', 'name' => 'Epileptischer Anfall (stattgehabt)'];
        yield ['code' => '413', 'name' => 'Kopf-/Gesichtsschmerz (bei SK1 NC!)'];
        yield ['code' => '414', 'name' => 'Vigilanzminderung /Koma (ohne Trauma)'];
        yield ['code' => '415', 'name' => 'Schwindel'];
        yield ['code' => '419', 'name' => 'Neurologischer Notfall, sonstiger'];
        yield ['code' => '421', 'name' => 'Schlaganfall / Blutung < 24 h oder unklar'];
        yield ['code' => '422', 'name' => 'Wie 421 Einsatzstelle -- > Thrombektomie'];
        yield ['code' => '423', 'name' => 'Schlaganfall / Blutung >24 h'];
        yield ['code' => '425', 'name' => 'Diagnostik Cereb. Gefäßverschl. zur Thrombektomie'];
        yield ['code' => '431', 'name' => 'Suizid, angedroht'];
        yield ['code' => '432', 'name' => 'Einweisung, psychiatrische'];
        yield ['code' => '433', 'name' => 'Einweisung (nach LandesPsychKG)'];
        yield ['code' => '434', 'name' => 'Einweisung (nach LandesPsychKG)"'];
        yield ['code' => '435', 'name' => 'Akute Verwirrtheit/Delir'];
        yield ['code' => '501', 'name' => 'Kinderheilkunde Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '502', 'name' => 'Kinderheilkunde Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '502', 'name' => 'Zuverlegung Inkubator'];
        yield ['code' => '511', 'name' => 'Pädiatrisch - Atemnot'];
        yield ['code' => '512', 'name' => 'schwerer Husten (Pseudokrupp)'];
        yield ['code' => '513', 'name' => 'pädiatrisch Fieberkrampf'];
        yield ['code' => '519', 'name' => 'Pädiatrischer Notfall, sonstiger'];
        yield ['code' => '521', 'name' => '<16 SSW'];
        yield ['code' => '522', 'name' => '16+0 SSW bis 21+6 SSW'];
        yield ['code' => '523', 'name' => '22+0 SSW bis 28+6 SSW'];
        yield ['code' => '524', 'name' => '29+0 SSW bis 31+6 SSW'];
        yield ['code' => '525', 'name' => '32+0 SSW bis 36+6 SSW + jede Wachstumsstörung'];
        yield ['code' => '526', 'name' => 'ab 37+0 SSW'];
        yield ['code' => '527', 'name' => 'ab 37+0 SSW + Diabetes'];
        yield ['code' => '528', 'name' => 'Drillinge bis 32+6, alle über 3 Mehrlinge'];
        yield ['code' => '529', 'name' => 'Drillinge ab 33+0'];
        yield ['code' => '531', 'name' => '<16 SSW'];
        yield ['code' => '532', 'name' => '16+0 SSW bis 21+6 SSW'];
        yield ['code' => '533', 'name' => '22+0 SSW bis 28+6 SSW'];
        yield ['code' => '534', 'name' => '29+0 SSW bis 31+6 SSW'];
        yield ['code' => '535', 'name' => '32+0 SSW bis 36+6 SSW + jede Wachstumsstörung'];
        yield ['code' => '536', 'name' => 'ab 37+0 SSW'];
        yield ['code' => '537', 'name' => 'ab 37+0 SSW + Diabetes'];
        yield ['code' => '538', 'name' => 'Drillinge bis 32+6, alle über 3 Mehrlinge'];
        yield ['code' => '539', 'name' => 'Drillinge ab 33+0'];
        yield ['code' => '530', 'name' => 'akute fetale Gefährdung, Erstversorgung ggf. in ungeeigneter Klinik'];
        yield ['code' => '541', 'name' => '<16 SSW'];
        yield ['code' => '542', 'name' => '16+0 SSW bis 21+6 SSW'];
        yield ['code' => '543', 'name' => '22+0 SSW bis 28+6 SSW'];
        yield ['code' => '544', 'name' => '29+0 SSW bis 31+6 SSW'];
        yield ['code' => '545', 'name' => '32+0 SSW bis 36+6 SSW + jede Wachstumsstörung'];
        yield ['code' => '546', 'name' => 'ab 37+0 SSW'];
        yield ['code' => '547', 'name' => 'ab 37+0 SSW + Diabetes'];
        yield ['code' => '548', 'name' => 'Drillinge bis 32+6, alle über 3 Mehrlinge'];
        yield ['code' => '549', 'name' => 'Drillinge ab 33+0'];
        yield ['code' => '540', 'name' => 'akute fetale Gefährdung, Erstversorgung ggf. in ungeeigneter Klinik'];
        yield ['code' => '551', 'name' => 'Vaginale Blutung'];
        yield ['code' => '552', 'name' => 'Unterbauchschmerzen'];
        yield ['code' => '553', 'name' => 'Sexualdelikt'];
        yield ['code' => '559', 'name' => 'Gynäkologischer Notfall, sonstiger'];
        yield ['code' => '601', 'name' => 'Transport zu definierten Leistungen'];
        yield ['code' => '601', 'name' => 'Transport zu geplanter Dialyse'];
        yield ['code' => '601', 'name' => 'Transport zu geplantem Herzkatheter'];
        yield ['code' => '601', 'name' => 'Transport zu geplantem CT'];
        yield ['code' => '601', 'name' => 'Transport zu geplantem MRT'];
        yield ['code' => '626', 'name' => 'Strategische Verlegung (z.B. Kleeblatt)'];
        yield ['code' => '631', 'name' => 'Entlassung aus stationärer Behandlung'];
        yield ['code' => '701', 'name' => 'Haut-u. Geschlechtskrankheiten'];
        yield ['code' => '711', 'name' => 'Nieren- Harnleiterkolik'];
        yield ['code' => '712', 'name' => 'Hodenschmerz'];
        yield ['code' => '713', 'name' => 'Harnverhalt (akut)'];
        yield ['code' => '714', 'name' => 'Hämaturie'];
        yield ['code' => '715', 'name' => 'Katheterwechsel (transurethral)'];
        yield ['code' => '716', 'name' => 'Katheterwechsel (suprapubisch)'];
        yield ['code' => '717', 'name' => 'Katheterverlust/-verstopfung'];
        yield ['code' => '718', 'name' => 'Harnwegsinfekt'];
        yield ['code' => '719', 'name' => 'Urologischer Notfall, sonstiger'];
        yield ['code' => '721', 'name' => 'Akute Augenerkrankung'];
        yield ['code' => '729', 'name' => 'Augennotfall, sonstiger'];
        yield ['code' => '731', 'name' => 'Nasenbluten (Epistaxis) unstillbar'];
        yield ['code' => '732', 'name' => '(Nach-)Blutung, HNO, akut'];
        yield ['code' => '733', 'name' => 'Tracheostoma-Komplikation'];
        yield ['code' => '739', 'name' => 'HNO Notfall, sonstiger'];
        yield ['code' => '749', 'name' => 'MKG Notfall, sonstiger'];
        yield ['code' => '751', 'name' => 'Geriatrische Einweisung'];
        yield ['code' => '779', 'name' => 'sonstige Notfallsituationen'];
        yield ['code' => '801', 'name' => 'Schmerz/Schwellung Bewegungsapparat (nicht traumatisch)'];
        yield ['code' => '802', 'name' => 'Schwellung/Abszeß sonstige Lokalisation'];
        yield ['code' => '809', 'name' => 'Allgemeinmedizin, sonstiger Notfall'];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['indication_normalized'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'indication_normalized';
    }
}
