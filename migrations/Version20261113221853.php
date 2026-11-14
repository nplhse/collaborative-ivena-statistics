<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MTS catalog in the database (ADR 032, #1082): diagrams, indicators, aliases and diagram–indicator assignments of
 * the Encounter context. Encounters keep storing plain codes, so there is no foreign key from `encounter`.
 *
 * Seeded with the content of `src/Encounter/Application/Catalog/mts-de.json` as of this migration (40 diagrams,
 * 70 indicators, status `curated`, source `orbis_export`) and the English diagram names that were translations
 * (`encounter.mts.diagram_name.*`) until now. The values are carried here so a later change to the file cannot
 * change the seed. Codes follow MtsCatalog::code(); no aliases and no assignments are seeded.
 */
final class Version20261113221853 extends AbstractMigration
{
    /** code => [German name, English name] */
    private const array DIAGRAMS = [
        'abdominelle_schmerzen_bei_erwachsenen' => ['Abdominelle Schmerzen bei Erwachsenen', 'Abdominal pain in adults'],
        'abdominelle_schmerzen_bei_kindern' => ['Abdominelle Schmerzen bei Kindern', 'Abdominal pain in children'],
        'abszesse_und_lokale_infektionen' => ['Abszesse und lokale Infektionen', 'Abscesses and local infections'],
        'allergie' => ['Allergie', 'Allergy'],
        'angriff_zustand_nach' => ['Angriff (Zustand nach)', 'Assault'],
        'atemproblem_bei_erwachsenen' => ['Atemproblem bei Erwachsenen', 'Shortness of breath in adults'],
        'auffaelliges_verhalten' => ['Auffälliges Verhalten', 'Behaving strangely'],
        'betrunkener_eindruck' => ['Betrunkener Eindruck', 'Apparently drunk'],
        'bisse_und_stiche' => ['Bisse und Stiche', 'Bites and stings'],
        'chemikalienkontakt' => ['Chemikalienkontakt', 'Exposure to chemicals'],
        'diabetes' => ['Diabetes', 'Diabetes'],
        'durchfaelle_und_erbrechen' => ['Durchfälle und Erbrechen', 'Diarrhoea and vomiting'],
        'extremitaetenprobleme' => ['Extremitätenprobleme', 'Limb problems'],
        'fremdkoerper' => ['Fremdkörper', 'Foreign body'],
        'gastrointestinale_blutung' => ['Gastrointestinale Blutung', 'GI bleeding'],
        'generelle_indikatoren' => ['Generelle Indikatoren', null],
        'gesichtsprobleme' => ['Gesichtsprobleme', 'Facial problems'],
        'halsschmerz' => ['Halsschmerz', 'Sore throat'],
        'hautausschlaege' => ['Hautausschläge', 'Rashes'],
        'herzklopfen' => ['Herzklopfen', 'Palpitations'],
        'hodenschmerz' => ['Hodenschmerz', 'Testicular pain'],
        'kollaps' => ['Kollaps', 'Collapsed adult'],
        'kopfschmerz' => ['Kopfschmerz', 'Headache'],
        'kopfverletzung' => ['Kopfverletzung', 'Head injury'],
        'krampfanfall' => ['Krampfanfall', 'Fits'],
        'nackenschmerz' => ['Nackenschmerz', 'Neck pain'],
        'ohrenprobleme' => ['Ohrenprobleme', 'Ear problems'],
        'psychische_erkrankung' => ['Psychische Erkrankung', 'Mental illness'],
        'rueckenschmerz' => ['Rückenschmerz', 'Back pain'],
        'schweres_trauma' => ['Schweres Trauma', 'Major trauma'],
        'selbstverletzung' => ['Selbstverletzung', 'Self-harm'],
        'sexualinfektion' => ['Sexualinfektion', 'Sexually acquired infection'],
        'stuerze' => ['Stürze', 'Falls'],
        'thoraxschmerz' => ['Thoraxschmerz', 'Chest pain'],
        'ueberdosierung_und_vergiftung' => ['Überdosierung und Vergiftung', 'Overdose and poisoning'],
        'unwohlsein_bei_erwachsenen' => ['Unwohlsein bei Erwachsenen', 'Unwell adult'],
        'unwohlsein_bei_kindern' => ['Unwohlsein bei Kindern', 'Unwell child'],
        'urologisches_problem' => ['Urologisches Problem', 'Urinary problems'],
        'verbrennungen_und_verbruehungen' => ['Verbrennungen und Verbrühungen', 'Burns and scalds'],
        'wunden' => ['Wunden', 'Wounds'],
    ];

    /** code => [German name, English name] */
    private const array INDICATORS = [
        'akutes_neurologisches_defizit_juenger_als_24h' => ['Akutes neurologisches Defizit jünger als 24h', null],
        'alter_25_jahre_oder_juenger' => ['Alter 25 Jahre oder jünger', null],
        'androhung_von_gewalt_oder_hohes_risiko_der_fremdgefaehrdung' => ['Androhung von Gewalt oder hohes Risiko der Fremdgefährdung', null],
        'anhaltendes_erbrechen' => ['Anhaltendes Erbrechen', null],
        'anhaltendes_herzklopfen' => ['Anhaltendes Herzklopfen', null],
        'anzeichen_fuer_dehydration' => ['Anzeichen für Dehydration', null],
        'auffaellige_haematologische_oder_metabolische_anamnese' => ['Auffällige hämatologische oder metabolische Anamnese', null],
        'auffaellige_kardiale_anamnese' => ['Auffällige kardiale Anamnese', null],
        'auffaellige_psychiatrische_anamnese' => ['Auffällige psychiatrische Anamnese', null],
        'auffaellige_respiratorische_anamnese' => ['Auffällige respiratorische Anamnese', null],
        'auffaelliger_verletzungsmechanismus' => ['Auffälliger Verletzungsmechanismus', null],
        'bericht_ueber_bewusstlosigkeit' => ['Bericht über Bewusstlosigkeit', null],
        'bericht_ueber_kopfverletzung' => ['Bericht über Kopfverletzung', null],
        'blutungsneigung' => ['Blutungsneigung', null],
        'direktes_rueckentrauma' => ['Direktes Rückentrauma', null],
        'dysurie' => ['Dysurie', null],
        'eigengefaehrdung_ohne_andere_psychische_erkrankung' => ['Eigengefährdung ohne andere psychische Erkrankung', null],
        'erbrechen' => ['Erbrechen', null],
        'erbrechen_von_blut' => ['Erbrechen von Blut', null],
        'erschoepfungszustand' => ['Erschöpfungszustand', null],
        'fehlstellung' => ['Fehlstellung', null],
        'frisch_oder_altblutiger_stuhlgang' => ['Frisch- oder altblutiger Stuhlgang', null],
        'frisches_neurologisches_defizit_aelter_als_24h' => ['Frisches neurologisches Defizit älter als 24h', null],
        'gefaehrdete_distale_durchblutung' => ['Gefährdete (distale) Durchblutung', null],
        'gefaehrdeter_atemweg' => ['Gefährdeter Atemweg', null],
        'grobe_fehlstellung' => ['Grobe Fehlstellung', null],
        'grossflaechiger_hautausschlag_oder_blaeschenbildung' => ['Großflächiger Hautausschlag oder Bläschenbildung', null],
        'harnverhalt' => ['Harnverhalt', null],
        'heiss' => ['Heiß', null],
        'hyperglykaemie_mit_ketose' => ['Hyperglykämie mit Ketose', null],
        'hypoglykaemie' => ['Hypoglykämie', null],
        'juenger_reduzierte_sehschaerfe' => ['Jünger reduzierte Sehschärfe', null],
        'juengerer_leichter_juckreiz' => ['Jüngerer leichter Juckreiz', null],
        'juengerer_leichter_schmerz' => ['Jüngerer leichter Schmerz', null],
        'juengeres_problem' => ['Jüngeres Problem', null],
        'kann_nicht_in_ganzen_saetzen_sprechen' => ['Kann nicht in ganzen Sätzen sprechen', null],
        'kardialer_schmerz' => ['Kardialer Schmerz', null],
        'keuchen' => ['Keuchen', null],
        'kolikartige_schmerzen' => ['Kolikartige Schmerzen', null],
        'kopfhauthaematom' => ['Kopfhauthämatom', null],
        'lokale_infektion' => ['Lokale Infektion', null],
        'maessiger_schmerz' => ['Mäßiger Schmerz', null],
        'maessiges_risiko_der_eigengefaehrdung' => ['Mäßiges Risiko der Eigengefährdung', null],
        'makrohaematurie' => ['Makrohämaturie', null],
        'meningismuszeichen' => ['Meningismuszeichen', null],
        'neuer_unnormaler_puls' => ['Neuer unnormaler Puls', null],
        'neuer_verwirrtheitszustand' => ['Neuer Verwirrtheitszustand', null],
        'nicht_verblassender_ausschlag' => ['Nicht verblassender Ausschlag', null],
        'niedrige_o2_saettigung' => ['Niedrige O2-Sättigung', null],
        'pleuraschmerz' => ['Pleuraschmerz', null],
        'produktiver_husten' => ['Produktiver Husten', null],
        'rasches_einsetzen' => ['Rasches Einsetzen', null],
        'schmerzen_in_der_schulterspitze' => ['Schmerzen in der Schulterspitze', null],
        'schock' => ['Schock', null],
        'schwellung' => ['Schwellung', null],
        'sehr_heiss' => ['Sehr heiß', null],
        'sehr_niedrige_o2_saettigung' => ['Sehr niedrige O2-Sättigung', null],
        'sepsisverdacht' => ['Sepsisverdacht', null],
        'skrotumschwellung_roetung' => ['Skrotumschwellung/-rötung', null],
        'staerkster_schmerz' => ['Stärkster Schmerz', null],
        'stridor' => ['Stridor', null],
        'teerstuhl_oder_frische_blutauflagerung' => ['Teerstuhl oder frische Blutauflagerung', null],
        'ueberwaermt' => ['Überwärmt', null],
        'unpassende_vorgeschichte' => ['Unpassende Vorgeschichte', null],
        'unstillbare_grosse_blutung' => ['Unstillbare große Blutung', null],
        'unstillbare_kleine_blutung' => ['Unstillbare kleine Blutung', null],
        'unterkuehlt' => ['Unterkühlt', null],
        'unzureichende_atmung' => ['Unzureichende Atmung', null],
        'veraenderter_bewusstseinszustand' => ['Veränderter Bewusstseinszustand', null],
        'veraenderter_bewusstseinszustand_vollstaendig_durch_alkoholgenus' => ['Veränderter Bewusstseinszustand vollständig durch Alkoholgenuss erklärbar', null],
    ];
    public function getDescription(): string
    {
        return 'Add the MTS catalog tables (encounter_mts_*) and seed them from mts-de.json (ADR 032, #1082)';
    }

    public function up(Schema $schema): void
    {
        foreach (['diagram', 'indicator'] as $kind) {
            $this->addSql(\sprintf('CREATE TABLE encounter_mts_%s (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, code VARCHAR(64) NOT NULL, german_name VARCHAR(255) NOT NULL, english_name VARCHAR(255) DEFAULT NULL, source VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))', $kind));
            $this->addSql(\sprintf('CREATE UNIQUE INDEX uniq_encounter_mts_%1$s_code ON encounter_mts_%1$s (code)', $kind));
            $this->addSql(\sprintf("ALTER TABLE encounter_mts_%1\$s ADD CONSTRAINT chk_encounter_mts_%1\$s_source CHECK (source IN ('orbis_export', 'mts_edition', 'manual', 'first_seen'))", $kind));
            $this->addSql(\sprintf("ALTER TABLE encounter_mts_%1\$s ADD CONSTRAINT chk_encounter_mts_%1\$s_status CHECK (status IN ('curated', 'provisional', 'merged'))", $kind));
        }

        $this->addSql('CREATE TABLE encounter_mts_alias (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, kind VARCHAR(16) NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(255) NOT NULL, target_code VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_encounter_mts_alias_kind_target ON encounter_mts_alias (kind, target_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_mts_alias_kind_code ON encounter_mts_alias (kind, code)');
        $this->addSql("ALTER TABLE encounter_mts_alias ADD CONSTRAINT chk_encounter_mts_alias_kind CHECK (kind IN ('diagram', 'indicator'))");
        // Resolution takes one step: an alias never points to itself (MtsCatalogCuration checks the rest).
        $this->addSql('ALTER TABLE encounter_mts_alias ADD CONSTRAINT chk_encounter_mts_alias_target CHECK (code <> target_code)');

        $this->addSql('CREATE TABLE encounter_mts_diagram_indicator (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, diagram_id INT NOT NULL, indicator_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_encounter_mts_diagram_indicator_indicator ON encounter_mts_diagram_indicator (indicator_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_mts_diagram_indicator ON encounter_mts_diagram_indicator (diagram_id, indicator_id)');
        $this->addSql('CREATE INDEX IDX_61B50F4D2BE2E806 ON encounter_mts_diagram_indicator (diagram_id)');
        $this->addSql('ALTER TABLE encounter_mts_diagram_indicator ADD CONSTRAINT FK_61B50F4D2BE2E806 FOREIGN KEY (diagram_id) REFERENCES encounter_mts_diagram (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE encounter_mts_diagram_indicator ADD CONSTRAINT FK_61B50F4D4402854A FOREIGN KEY (indicator_id) REFERENCES encounter_mts_indicator (id) ON DELETE CASCADE NOT DEFERRABLE');

        foreach (['diagram' => self::DIAGRAMS, 'indicator' => self::INDICATORS] as $kind => $entries) {
            foreach ($entries as $code => [$germanName, $englishName]) {
                $this->addSql(
                    \sprintf("INSERT INTO encounter_mts_%s (code, german_name, english_name, source, status, created_at) VALUES (:code, :german_name, :english_name, 'orbis_export', 'curated', LOCALTIMESTAMP(0))", $kind),
                    ['code' => $code, 'german_name' => $germanName, 'english_name' => $englishName],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE encounter_mts_diagram_indicator');
        $this->addSql('DROP TABLE encounter_mts_alias');
        $this->addSql('DROP TABLE encounter_mts_indicator');
        $this->addSql('DROP TABLE encounter_mts_diagram');
    }
}
