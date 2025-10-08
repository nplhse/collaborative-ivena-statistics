<?php

namespace App\Service\Seed;

use App\Entity\IndicationRaw;
use App\Entity\User;
use App\Service\Import\Indication\IndicationKey;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<array{code:string, name:string}>
 */
#[AsTaggedItem('app.seed_provider')]
final class IndicationRawSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<IndicationRaw>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        foreach ($this->provide() as $row) {
            $entity = new IndicationRaw()
                ->setName($row['name'])
                ->setCode((int) $row['code'])
                ->setHash(IndicationKey::hashFrom($row['code'], $row['name']))
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
        yield ['code' => '111', 'name' => 'primäre Todesfeststellung'];
        yield ['code' => '121', 'name' => 'Reanimation ohne ROSC'];
        yield ['code' => '201', 'name' => 'Chirurgie Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '202', 'name' => 'Chirurgie Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '203', 'name' => 'Chirurgie Zu-Verlegung IMC mit Arzt'];
        yield ['code' => '204', 'name' => 'Chirurgie Zu-Verlegung IMC ohne Arzt'];
        yield ['code' => '205', 'name' => 'Neurochirurgie Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '206', 'name' => 'Neurochirurgie Zu-Verlegung  Intensiv ohne Arzt'];
        yield ['code' => '210', 'name' => 'sonstige kombinierte Verletzung'];
        yield ['code' => '211', 'name' => 'Polytrauma mit SHT'];
        yield ['code' => '212', 'name' => 'Polytrauma ohne SHT'];
        yield ['code' => '213', 'name' => 'Schockraumindikation nach Unfallhergang'];
        yield ['code' => '214', 'name' => 'Gesichts-/Kopfverletzung'];
        yield ['code' => '215', 'name' => 'Gesichts-/Kopfverletzung mit Augenbeteiligung'];
        yield ['code' => '216', 'name' => 'Gesichts-/Kopfverletzung mit MKG Beteiligung'];
        yield ['code' => '217', 'name' => 'Gesichts-/Kopfverletzung mit HNO Beteiligung'];
        yield ['code' => '221', 'name' => 'Kopf SHT offen'];
        yield ['code' => '222', 'name' => 'Kopf SHT geschlossen'];
        yield ['code' => '223', 'name' => 'SAB'];
        yield ['code' => '230', 'name' => 'sonstige thoraxchirurgische Verletzung'];
        yield ['code' => '231', 'name' => 'Thorax penetrierend'];
        yield ['code' => '232', 'name' => 'Thorax geschlossen/stumpf'];
        yield ['code' => '233', 'name' => 'Pneumothorax'];
        yield ['code' => '240', 'name' => 'sonstiger viszeralchirurgischer Notfall'];
        yield ['code' => '241', 'name' => 'Abdomen penetrierend'];
        yield ['code' => '242', 'name' => 'Abdomen geschlossen/stumpf'];
        yield ['code' => '243', 'name' => 'Akutes Abdomen (nicht traumatisch)'];
        yield ['code' => '244', 'name' => 'Appendizitis'];
        yield ['code' => '251', 'name' => 'Wirbelsäulentrauma mit neurologischen Ausfällen'];
        yield ['code' => '252', 'name' => 'Wirbelsäulentrauma ohne neurologische Ausfälle'];
        yield ['code' => '253', 'name' => 'Rückenschmerz nicht traum. mit neuro. Ausfällen'];
        yield ['code' => '254', 'name' => 'Rückenschmerz nicht traum. ohne neuro Ausfällen'];
        yield ['code' => '261', 'name' => 'Becken penetrierend'];
        yield ['code' => '262', 'name' => 'Becken geschlossen/stumpf'];
        yield ['code' => '270', 'name' => 'Sonstige kombinierte Extremitäten Verletzung'];
        yield ['code' => '271', 'name' => 'Extremitäten offen'];
        yield ['code' => '272', 'name' => 'Extremitäten geschlossen'];
        yield ['code' => '273', 'name' => 'Extremitäten geschlossen'];
        yield ['code' => '273', 'name' => 'Schenkelhals'];
        yield ['code' => '274', 'name' => 'Luxation'];
        yield ['code' => '275', 'name' => 'Handverletzung'];
        yield ['code' => '276', 'name' => 'Hand-Amputation'];
        yield ['code' => '277', 'name' => 'Finger-Amputation'];
        yield ['code' => '278', 'name' => 'Extremitäten-Amputation'];
        yield ['code' => '281', 'name' => 'Verbrennung /Verbrühung'];
        yield ['code' => '282', 'name' => 'Verätzung (äußerlich)'];
        yield ['code' => '283', 'name' => 'Blitzschlag/Hochspannungstrauma'];
        yield ['code' => '290', 'name' => 'sonstiger gefäßchirurgischer Notfall'];
        yield ['code' => '291', 'name' => 'Extremitäten mit Gefäß-/Nervenverletzung'];
        yield ['code' => '292', 'name' => 'Aortenaneurysma'];
        yield ['code' => '293', 'name' => 'Extremitätenischaemie (akut)'];
        yield ['code' => '294', 'name' => 'Aorta ascendens Dissektion'];
        yield ['code' => '301', 'name' => 'Innere Medizin Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '302', 'name' => 'Innere Medizin Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '303', 'name' => 'Innere Medizin Zu-Verlegung IMC mit Arzt'];
        yield ['code' => '304', 'name' => 'Innere Medizin Zu-Verlegung IMC ohne Arzt'];
        yield ['code' => '310', 'name' => 'sonstiger respiratorischer Notfall'];
        yield ['code' => '311', 'name' => 'Atemnot (unklar)'];
        yield ['code' => '312', 'name' => 'Obstruktion (Asthma / COPD)'];
        yield ['code' => '313', 'name' => 'Hämoptoe/Hämoptysen'];
        yield ['code' => '314', 'name' => '(Bolus-) Aspiration'];
        yield ['code' => '315', 'name' => 'Bronchitis/Pneumonie'];
        yield ['code' => '316', 'name' => 'Hyperventilation'];
        yield ['code' => '317', 'name' => 'Rauchgas/Reizgas'];
        yield ['code' => '318', 'name' => 'Spontanpneumothorax'];
        yield ['code' => '319', 'name' => '(Beinahe-) Ertrinken'];
        yield ['code' => '320', 'name' => 'sonstiger internistischer Notfall'];
        yield ['code' => '321', 'name' => 'Anaphylaxie/Unverträglichkeitsreaktion'];
        yield ['code' => '322', 'name' => 'sonstiger respiratorischer Notfall'];
        yield ['code' => '323', 'name' => 'Hypertonie'];
        yield ['code' => '324', 'name' => 'Hypotonie'];
        yield ['code' => '324', 'name' => 'sonstige Notfallsituation'];
        yield ['code' => '325', 'name' => 'Thrombose'];
        yield ['code' => '326', 'name' => 'unklares Fieber'];
        yield ['code' => '327', 'name' => 'Hitzeerschöpfung/Hitzschlag'];
        yield ['code' => '328', 'name' => 'Unterkühlung/Erfrierung'];
        yield ['code' => '329', 'name' => 'Exsikkose'];
        yield ['code' => '330', 'name' => 'sonstiger kardiologischer Notfall'];
        yield ['code' => '331', 'name' => 'STEMI  < 12h (EKG gesichert)'];
        yield ['code' => '332', 'name' => 'STEMI  > 12h (EKG gesichert)'];
        yield ['code' => '333', 'name' => 'Akutes Koronarsyndrom (Sonstiges)'];
        yield ['code' => '341', 'name' => 'Arrhythmie/Rhythmusstörungen'];
        yield ['code' => '342', 'name' => 'bradykarde Rhythmusstörungen'];
        yield ['code' => '343', 'name' => 'Hypertonie'];
        yield ['code' => '343', 'name' => 'tachykarde Rhythmusstörungen'];
        yield ['code' => '344', 'name' => 'Elektrounfall (Schwachstrom)'];
        yield ['code' => '347', 'name' => 'Kardiogener Schock'];
        yield ['code' => '348', 'name' => 'Herzinsuffizienz/Lungenödem'];
        yield ['code' => '349', 'name' => 'Lungenembolie'];
        yield ['code' => '350', 'name' => 'sonstiger gastroenterologischer Notfall'];
        yield ['code' => '351', 'name' => 'Obere GI-Blutung'];
        yield ['code' => '352', 'name' => 'Untere GI-Blutung'];
        yield ['code' => '353', 'name' => 'Unklares Abdomen'];
        yield ['code' => '354', 'name' => 'Infektiöse Gastroenteritis'];
        yield ['code' => '355', 'name' => 'Hämaturie'];
        yield ['code' => '355', 'name' => 'Sonstige Gastroenteritis'];
        yield ['code' => '356', 'name' => 'Verätzung/Ingestion (innerlich)'];
        yield ['code' => '357', 'name' => 'Kolik'];
        yield ['code' => '360', 'name' => 'sonstige Intoxikationen'];
        yield ['code' => '361', 'name' => 'Alkohol'];
        yield ['code' => '362', 'name' => 'Drogen / Rauschgift'];
        yield ['code' => '363', 'name' => 'Mischintoxikation Alkohol/Drogen/Medikament'];
        yield ['code' => '364', 'name' => 'Lebensmittel'];
        yield ['code' => '365', 'name' => 'Medikamente'];
        yield ['code' => '366', 'name' => 'Pflanzenschutzmittel'];
        yield ['code' => '367', 'name' => 'Tierische Gifte / Giftpflanzen'];
        yield ['code' => '370', 'name' => 'sonstiger infektiologischer Notfall'];
        yield ['code' => '371', 'name' => 'Definierte Infektionskrankheit'];
        yield ['code' => '372', 'name' => 'Meningitis'];
        yield ['code' => '373', 'name' => 'NTBC'];
        yield ['code' => '374', 'name' => 'septischer Schock'];
        yield ['code' => '376', 'name' => '(SARS-CoV-2) Covid-19 bestätigt'];
        yield ['code' => '377', 'name' => '(SARS-CoV-2) Covid-19 nicht bestätigt'];
        yield ['code' => '379', 'name' => 'Hochkontagiöse Erkrankung'];
        yield ['code' => '391', 'name' => 'Diabetischer Notfall'];
        yield ['code' => '392', 'name' => 'Hyperglykämie'];
        yield ['code' => '393', 'name' => 'Hypoglykämie'];
        yield ['code' => '394', 'name' => 'Akuter endokrinologischer Notfall (nicht 391)'];
        yield ['code' => '401', 'name' => 'Neurologie Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '402', 'name' => 'Neurologie Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '410', 'name' => 'sonstiger neurologischer Notfall'];
        yield ['code' => '411', 'name' => 'Krampfanfall bei bekanntem Krampfleiden'];
        yield ['code' => '412', 'name' => 'erstmaliger Krampfanfall'];
        yield ['code' => '413', 'name' => 'Kopf-/Gesichtsschmerzen'];
        yield ['code' => '414', 'name' => 'unklare Bewusstlosigkeit'];
        yield ['code' => '421', 'name' => 'Apoplex/TIA/Blutung  < 6 h'];
        yield ['code' => '422', 'name' => 'Apoplex/TIA/Blutung  6-24 h'];
        yield ['code' => '423', 'name' => 'Apoplex/TIA/Blutung >24h'];
        yield ['code' => '425', 'name' => 'Cerebraler Gefäßverschluss zur Thrombektomie'];
        yield ['code' => '430', 'name' => 'sonstiger psychiatrischer Notfall'];
        yield ['code' => '431', 'name' => 'Akute Suizidalität'];
        yield ['code' => '434', 'name' => 'Fachpsychiatrische Unterbringung'];
        yield ['code' => '435', 'name' => 'Unterbringung nach PSychKHG'];
        yield ['code' => '436', 'name' => 'Akuter Erregungszustand '];
        yield ['code' => '437', 'name' => 'Akute Psychose'];
        yield ['code' => '438', 'name' => 'Entzugssyndrom'];
        yield ['code' => '501', 'name' => 'Kinderheilkunde Zu-Verlegung Intensiv mit Arzt'];
        yield ['code' => '502', 'name' => 'Kinderheilkunde Zu-Verlegung Intensiv ohne Arzt'];
        yield ['code' => '503', 'name' => 'Zuverlegung Inkubator'];
        yield ['code' => '510', 'name' => 'sonstige pädiatrische Notfall'];
        yield ['code' => '511', 'name' => 'Pädiatrisch - Atemnot'];
        yield ['code' => '512', 'name' => 'Krupp / Pseudokrupp'];
        yield ['code' => '513', 'name' => 'Pädiatrisch Fieberkrampf'];
        yield ['code' => '514', 'name' => 'Pädiatrisch Epilepsie'];
        yield ['code' => '515', 'name' => 'Akuter kindlicher Hüftschmerz'];
        yield ['code' => '520', 'name' => 'sonstiger Notfall in der Schwangerschaft'];
        yield ['code' => '521', 'name' => 'Entbindung/Einsetzende Geburt > 36.SSW'];
        yield ['code' => '521', 'name' => 'Entbindung/Einsetzende Geburt >= 36.SSW'];
        yield ['code' => '522', 'name' => 'Entbindung/Einsetzende Geburt > 32. und < 36.SSW'];
        yield ['code' => '522', 'name' => 'Entbindung/Einsetzende Geburt >= 32. und < 36.SSW'];
        yield ['code' => '523', 'name' => 'Entbindung/Einsetzende Geburt > 29. und < 32.SSW'];
        yield ['code' => '523', 'name' => 'Entbindung/Einsetzende Geburt >= 29. und < 32.SSW'];
        yield ['code' => '524', 'name' => 'Entbindung/Einsetzende Geburt < 29.SSW'];
        yield ['code' => '525', 'name' => 'Präklinische Geburt '];
        yield ['code' => '526', 'name' => 'Präklinische Frühgeburt < 36. SSW'];
        yield ['code' => '527', 'name' => 'Eklampsie '];
        yield ['code' => '528', 'name' => 'Vaginale Blutung w. d. Schwangerschaft < 20. SSW'];
        yield ['code' => '529', 'name' => 'Vaginale Blutung w. d. Schwangerschaft > 20. SSW'];
        yield ['code' => '529', 'name' => 'Vaginale Blutung w. d. Schwangerschaft >= 20. SSW'];
        yield ['code' => '530', 'name' => 'sonstiger gynäkologischer Notfall'];
        yield ['code' => '531', 'name' => 'Vaginale Blutung'];
        yield ['code' => '532', 'name' => 'Unterbauchschmerzen'];
        yield ['code' => '533', 'name' => 'Sexualdelikt'];
        yield ['code' => '601', 'name' => 'Transport zu definierter Leistung (KT)'];
        yield ['code' => '602', 'name' => 'Transport zu geplanter Dialyse'];
        yield ['code' => '603', 'name' => 'Transport zu geplantem Herzkatheter'];
        yield ['code' => '604', 'name' => 'Transport zu geplanter CT'];
        yield ['code' => '605', 'name' => 'Transport zu geplanter MRT'];
        yield ['code' => '606', 'name' => ''];
        yield ['code' => '607', 'name' => ''];
        yield ['code' => '625', 'name' => 'Sekundärverlegung Covid-Patienten'];
        yield ['code' => '626', 'name' => 'Zu- Verlegung Sonderlage Ukraine'];
        yield ['code' => '702', 'name' => 'Geriatrie Einweisung'];
        yield ['code' => '703', 'name' => 'Haut- u- Geschlechtskrankheit Einweisung'];
        yield ['code' => '710', 'name' => 'sonstiger urologischer Notfall'];
        yield ['code' => '711', 'name' => 'Nieren- Harnleiterkolik'];
        yield ['code' => '711', 'name' => 'Unklares Abdomen'];
        yield ['code' => '712', 'name' => 'Hodenschmerz'];
        yield ['code' => '713', 'name' => 'Harnverhalt'];
        yield ['code' => '713', 'name' => 'Katheterwechsel (transurethral)'];
        yield ['code' => '714', 'name' => 'Hämaturie'];
        yield ['code' => '715', 'name' => 'Katheterwechsel (transurethral)'];
        yield ['code' => '716', 'name' => 'Katheterwechsel (suprapubisch)'];
        yield ['code' => '717', 'name' => 'Katheterverlust'];
        yield ['code' => '718', 'name' => 'Harnwegsinfekt'];
        yield ['code' => '718', 'name' => 'Katheterwechsel (transurethral)'];
        yield ['code' => '720', 'name' => 'sonstiger ophthalmologischer Notfall'];
        yield ['code' => '721', 'name' => 'Augenverletzung mit Fremdkörper'];
        yield ['code' => '722', 'name' => 'Augenverletzung ohne Fremdkörper'];
        yield ['code' => '723', 'name' => 'Akute Augenerkrankung'];
        yield ['code' => '730', 'name' => 'sonstiger HNO Notfall'];
        yield ['code' => '731', 'name' => 'Epistaxis'];
        yield ['code' => '732', 'name' => 'Barotrauma'];
        yield ['code' => '733', 'name' => 'Hörsturz'];
        yield ['code' => '734', 'name' => 'HNO Nachblutung'];
        yield ['code' => '741', 'name' => 'MKG Einweisung'];
        yield ['code' => '751', 'name' => 'Strahlentrauma'];
        yield ['code' => '761', 'name' => 'Kohlenmonoxid-Vergiftung'];
        yield ['code' => '770', 'name' => 'sonstige Notfallsituation'];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['indication_raw'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'indication_raw';
    }
}
