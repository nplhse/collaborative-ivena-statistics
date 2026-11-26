<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Plausibility table of PZC and MTS diagram (ADR 033, #1153): curated problem areas with their PZC codes and plausible
 * MTS diagrams, snapshots with their PZC → area memberships and cells.
 *
 * Seeded with the a-priori draft of #1136 (no real data, no counts): 24 problem areas, every plausible pair with +3.
 * The values are carried here so a later change to the code cannot change the seed. From the areas the migration
 * builds one snapshot with status `draft` and origin `seed`, with the same rule as PlausibilitySnapshotBuilder: the
 * memberships, one fallback cell per area × diagram and one exact cell per PZC × diagram of its area, all `curated`
 * with counts 0. No snapshot is active, so the bonus stays 0 until a person activates one; nothing reads the table for
 * matching before #1154.
 *
 * PZC codes follow the official list 1.3.11 (`PzcCodes`). Codes named "sonstige"/"sonstiger" as a catch-all, transfers
 * (KST), resuscitation, obstetrics, paediatrics and the other specialties belong to no area.
 */
final class Version20261113235145 extends AbstractMigration
{
    /** code => [German name, English name, PZC codes, plausible diagram codes] */
    private const array AREAS = [
        'urologie' => ['Urologie', 'Urology', [263, 711, 712, 713, 714, 715, 716, 717, 718], ['urologisches_problem', 'hodenschmerz', 'rueckenschmerz', 'abdominelle_schmerzen_bei_erwachsenen']],
        'mehrfachverletzung' => ['Mehrfachverletzung', 'Multiple injuries', [211, 212, 213, 214, 215, 219], ['extremitaetenprobleme', 'kopfverletzung', 'stuerze', 'wunden', 'schweres_trauma', 'angriff_zustand_nach']],
        'kopf_und_gesichtsverletzung' => ['Kopf- und Gesichtsverletzung', 'Head and face injury', [221, 222, 223, 224, 225], ['kopfverletzung', 'gesichtsprobleme', 'stuerze', 'wunden', 'schweres_trauma', 'angriff_zustand_nach']],
        'thoraxtrauma' => ['Thoraxtrauma', 'Chest trauma', [231, 232, 233], ['schweres_trauma']],
        'abdominaltrauma' => ['Abdominaltrauma', 'Abdominal trauma', [241, 242], ['abdominelle_schmerzen_bei_erwachsenen', 'schweres_trauma']],
        'abdomen_und_gastroenterologie' => ['Abdomen und Gastroenterologie', 'Abdomen and gastroenterology', [243, 291, 351, 353, 354], ['abdominelle_schmerzen_bei_erwachsenen', 'gastrointestinale_blutung', 'durchfaelle_und_erbrechen']],
        'rueckenschmerz' => ['Rückenschmerz', 'Back pain', [253, 254], ['rueckenschmerz', 'stuerze']],
        'wirbelsaeulenverletzung' => ['Wirbelsäulenverletzung', 'Spinal injury', [251, 252, 255], ['rueckenschmerz', 'stuerze', 'schweres_trauma']],
        'extremitaeten' => ['Extremitäten', 'Limbs', [261, 262, 271, 272, 273, 274, 275, 276, 277, 292, 324], ['extremitaetenprobleme', 'stuerze', 'wunden', 'schweres_trauma']],
        'verbrennung_und_veraetzung' => ['Verbrennung und Verätzung', 'Burns and chemical injury', [281, 282, 283], ['verbrennungen_und_verbruehungen', 'chemikalienkontakt', 'schweres_trauma']],
        'umweltbedingte_notfaelle' => ['Umweltbedingte Notfälle', 'Environmental emergencies', [284, 285, 286, 287, 288], ['kollaps', 'atemproblem_bei_erwachsenen']],
        'atemwege_und_lunge' => ['Atemwege und Lunge', 'Airway and lungs', [311, 312, 313, 314, 315, 316, 317, 318], ['atemproblem_bei_erwachsenen']],
        'anaphylaxie' => ['Anaphylaxie', 'Anaphylaxis', [321], ['allergie']],
        'synkope_und_kreislauf' => ['Synkope und Kreislauf', 'Syncope and circulation', [322, 323], ['kollaps']],
        'fieber' => ['Fieber', 'Fever', [325], ['urologisches_problem']],
        'exsikkose' => ['Exsikkose', 'Dehydration', [326], ['stuerze', 'durchfaelle_und_erbrechen']],
        'brustschmerz_und_akutes_koronarsyndrom' => ['Brustschmerz und akutes Koronarsyndrom', 'Chest pain and acute coronary syndrome', [293, 331, 332, 333], ['thoraxschmerz']],
        'herz_und_kreislauf' => ['Herz und Kreislauf', 'Heart and circulation', [341, 342, 343, 344, 345, 346, 347, 348], ['thoraxschmerz', 'herzklopfen', 'kollaps', 'atemproblem_bei_erwachsenen']],
        'rauchgas_und_kohlenmonoxid' => ['Rauchgas und Kohlenmonoxid', 'Smoke and carbon monoxide', [360, 370], ['ueberdosierung_und_vergiftung', 'atemproblem_bei_erwachsenen']],
        'psychiatrie_und_intoxikation' => ['Psychiatrie und Intoxikation', 'Psychiatry and intoxication', [361, 362, 363, 364, 365, 366, 367, 368, 431, 432, 433, 434, 435], ['betrunkener_eindruck', 'selbstverletzung', 'ueberdosierung_und_vergiftung', 'psychische_erkrankung', 'auffaelliges_verhalten', 'angriff_zustand_nach']],
        'sepsis' => ['Sepsis', 'Sepsis', [373, 374], ['urologisches_problem']],
        'infektionen' => ['Infektionen', 'Infections', [371, 372, 375, 376, 377, 378], []],
        'stoffwechsel' => ['Stoffwechsel', 'Metabolism', [391, 392, 393], ['diabetes']],
        'neurologie' => ['Neurologie', 'Neurology', [411, 412, 413, 414, 415, 421, 422, 423, 425], ['kollaps', 'krampfanfall', 'auffaelliges_verhalten', 'kopfschmerz']],
    ];

    private const int SEED_POINTS = 3;

    public function getDescription(): string
    {
        return 'Add the plausibility table (encounter_plausibility_*) with the curated seed as a draft snapshot (ADR 033, #1153)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE encounter_plausibility_area (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, code VARCHAR(64) NOT NULL, german_name VARCHAR(255) NOT NULL, english_name VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_plausibility_area_code ON encounter_plausibility_area (code)');

        $this->addSql('CREATE TABLE encounter_plausibility_area_code (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, pzc SMALLINT NOT NULL, area_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_plausibility_area_code_pzc ON encounter_plausibility_area_code (pzc)');
        $this->addSql('CREATE INDEX IDX_E54F0242BD0F409C ON encounter_plausibility_area_code (area_id)');
        $this->addSql('ALTER TABLE encounter_plausibility_area_code ADD CONSTRAINT FK_E54F0242BD0F409C FOREIGN KEY (area_id) REFERENCES encounter_plausibility_area (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE encounter_plausibility_area_code ADD CONSTRAINT chk_encounter_plausibility_area_code_pzc CHECK (pzc BETWEEN 0 AND 999)');

        $this->addSql('CREATE TABLE encounter_plausibility_area_diagram (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, points SMALLINT NOT NULL, area_id INT NOT NULL, diagram_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_encounter_plausibility_area_diagram_diagram ON encounter_plausibility_area_diagram (diagram_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_plausibility_area_diagram ON encounter_plausibility_area_diagram (area_id, diagram_id)');
        $this->addSql('CREATE INDEX IDX_318DDF8FBD0F409C ON encounter_plausibility_area_diagram (area_id)');
        $this->addSql('ALTER TABLE encounter_plausibility_area_diagram ADD CONSTRAINT FK_318DDF8FBD0F409C FOREIGN KEY (area_id) REFERENCES encounter_plausibility_area (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE encounter_plausibility_area_diagram ADD CONSTRAINT FK_318DDF8F2BE2E806 FOREIGN KEY (diagram_id) REFERENCES encounter_mts_diagram (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE encounter_plausibility_area_diagram ADD CONSTRAINT chk_encounter_plausibility_area_diagram_points CHECK (points BETWEEN 0 AND 3)');

        $this->addSql('CREATE TABLE encounter_plausibility_snapshot (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, status VARCHAR(16) NOT NULL, origin VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, activated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, retired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql("CREATE UNIQUE INDEX uniq_encounter_plausibility_snapshot_active ON encounter_plausibility_snapshot (status) WHERE ((status)::text = 'active'::text)");
        $this->addSql("ALTER TABLE encounter_plausibility_snapshot ADD CONSTRAINT chk_encounter_plausibility_snapshot_status CHECK (status IN ('draft', 'active', 'retired'))");
        $this->addSql("ALTER TABLE encounter_plausibility_snapshot ADD CONSTRAINT chk_encounter_plausibility_snapshot_origin CHECK (origin IN ('seed', 'learned'))");

        $this->addSql('CREATE TABLE encounter_plausibility_snapshot_code (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, pzc SMALLINT NOT NULL, area_code VARCHAR(64) NOT NULL, snapshot_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_plausibility_snapshot_code ON encounter_plausibility_snapshot_code (snapshot_id, pzc)');
        $this->addSql('CREATE INDEX IDX_9619D4987B39395E ON encounter_plausibility_snapshot_code (snapshot_id)');
        $this->addSql('ALTER TABLE encounter_plausibility_snapshot_code ADD CONSTRAINT FK_9619D4987B39395E FOREIGN KEY (snapshot_id) REFERENCES encounter_plausibility_snapshot (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE encounter_plausibility_cell (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, pzc SMALLINT DEFAULT NULL, area_code VARCHAR(64) DEFAULT NULL, diagram_code VARCHAR(64) NOT NULL, indicator_code VARCHAR(64) DEFAULT NULL, points SMALLINT NOT NULL, source VARCHAR(16) NOT NULL, positives INT NOT NULL, negatives INT NOT NULL, snapshot_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_encounter_plausibility_cell_snapshot ON encounter_plausibility_cell (snapshot_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_plausibility_cell_exact ON encounter_plausibility_cell (snapshot_id, pzc, diagram_code) WHERE ((pzc IS NOT NULL) AND (indicator_code IS NULL))');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_plausibility_cell_indicator ON encounter_plausibility_cell (snapshot_id, pzc, diagram_code, indicator_code) WHERE (indicator_code IS NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_plausibility_cell_area ON encounter_plausibility_cell (snapshot_id, area_code, diagram_code) WHERE (area_code IS NOT NULL)');
        $this->addSql('ALTER TABLE encounter_plausibility_cell ADD CONSTRAINT FK_825C04117B39395E FOREIGN KEY (snapshot_id) REFERENCES encounter_plausibility_snapshot (id) ON DELETE CASCADE NOT DEFERRABLE');
        // A cell is keyed by exactly one of PZC or problem area; the indicator level needs an exact PZC; no negative points.
        $this->addSql('ALTER TABLE encounter_plausibility_cell ADD CONSTRAINT chk_encounter_plausibility_cell_key CHECK ((pzc IS NULL) <> (area_code IS NULL) AND (indicator_code IS NULL OR pzc IS NOT NULL))');
        $this->addSql('ALTER TABLE encounter_plausibility_cell ADD CONSTRAINT chk_encounter_plausibility_cell_points CHECK (points BETWEEN 0 AND 3)');
        $this->addSql('ALTER TABLE encounter_plausibility_cell ADD CONSTRAINT chk_encounter_plausibility_cell_counts CHECK (positives >= 0 AND negatives >= 0)');
        $this->addSql("ALTER TABLE encounter_plausibility_cell ADD CONSTRAINT chk_encounter_plausibility_cell_source CHECK (source IN ('curated', 'learned'))");

        foreach (self::AREAS as $code => [$germanName, $englishName, $pzcCodes, $diagrams]) {
            $this->addSql(
                'INSERT INTO encounter_plausibility_area (code, german_name, english_name, created_at) VALUES (:code, :german_name, :english_name, LOCALTIMESTAMP(0))',
                ['code' => $code, 'german_name' => $germanName, 'english_name' => $englishName],
            );
            foreach ($pzcCodes as $pzc) {
                $this->addSql(
                    'INSERT INTO encounter_plausibility_area_code (area_id, pzc) SELECT id, :pzc FROM encounter_plausibility_area WHERE code = :code',
                    ['pzc' => $pzc, 'code' => $code],
                );
            }

            foreach ($diagrams as $diagram) {
                // Fails on a diagram missing from the catalog seed of Version20261113221853 (NOT NULL diagram_id).
                $this->addSql(
                    'INSERT INTO encounter_plausibility_area_diagram (area_id, diagram_id, points) VALUES ((SELECT id FROM encounter_plausibility_area WHERE code = :area), (SELECT id FROM encounter_mts_diagram WHERE code = :diagram), :points)',
                    ['area' => $code, 'diagram' => $diagram, 'points' => self::SEED_POINTS],
                );
            }
        }

        // The seed draft, built like PlausibilitySnapshotBuilder::buildFromAreas().
        $this->addSql("INSERT INTO encounter_plausibility_snapshot (status, origin, created_at) VALUES ('draft', 'seed', LOCALTIMESTAMP(0))");
        $this->addSql('INSERT INTO encounter_plausibility_snapshot_code (snapshot_id, pzc, area_code) SELECT (SELECT MAX(id) FROM encounter_plausibility_snapshot), c.pzc, a.code FROM encounter_plausibility_area_code c JOIN encounter_plausibility_area a ON a.id = c.area_id');
        $this->addSql("INSERT INTO encounter_plausibility_cell (snapshot_id, pzc, area_code, diagram_code, indicator_code, points, source, positives, negatives) SELECT (SELECT MAX(id) FROM encounter_plausibility_snapshot), NULL, a.code, d.code, NULL, r.points, 'curated', 0, 0 FROM encounter_plausibility_area_diagram r JOIN encounter_plausibility_area a ON a.id = r.area_id JOIN encounter_mts_diagram d ON d.id = r.diagram_id");
        $this->addSql("INSERT INTO encounter_plausibility_cell (snapshot_id, pzc, area_code, diagram_code, indicator_code, points, source, positives, negatives) SELECT (SELECT MAX(id) FROM encounter_plausibility_snapshot), c.pzc, NULL, d.code, NULL, r.points, 'curated', 0, 0 FROM encounter_plausibility_area_code c JOIN encounter_plausibility_area_diagram r ON r.area_id = c.area_id JOIN encounter_mts_diagram d ON d.id = r.diagram_id");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE encounter_plausibility_cell');
        $this->addSql('DROP TABLE encounter_plausibility_snapshot_code');
        $this->addSql('DROP TABLE encounter_plausibility_snapshot');
        $this->addSql('DROP TABLE encounter_plausibility_area_diagram');
        $this->addSql('DROP TABLE encounter_plausibility_area_code');
        $this->addSql('DROP TABLE encounter_plausibility_area');
    }
}
