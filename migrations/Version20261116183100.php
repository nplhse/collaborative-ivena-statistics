<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Splits the ED visit out of the encounter (#1300, ADR 037): `encounter` keeps the base data of the treatment
 * case (id, hospital, key generation, case key, anchor, age, sex, created at, effective IVENA assignment), the
 * new `ed_visit` gets every ED column, the visit fingerprint and its uniqueness, the visit uid and the owning
 * import. 1:1: each visit keeps `encounter.id` as `ed_visit.encounter_id` and its `visit_uid`, so URLs, ED
 * matching history, suspected duplicates, validation items and review decisions keep their references.
 *
 * `ed_visit.encounter_id` has no ON DELETE action: the database refuses to remove an encounter that a visit
 * references (checked at the end of the statement, so deleting a hospital still cascades through both).
 *
 * Down moves every ED visit back into `encounter` with the same id and uid. Encounters without ED visit cannot
 * be expressed in the old model and are deleted with it (documented in upgrading.md).
 */
final class Version20261116183100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split ed_visit out of encounter (#1300)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ed_visit (visit_uid UUID NOT NULL, key_generation INT NOT NULL, visit_fingerprint VARCHAR(64) NOT NULL, anchor_source VARCHAR(16) NOT NULL, arrival_mode VARCHAR(32) DEFAULT NULL, arrival_route VARCHAR(16) NOT NULL, referral_source VARCHAR(32) DEFAULT NULL, admission_type VARCHAR(16) DEFAULT NULL, case_status VARCHAR(16) DEFAULT NULL, work_accident BOOLEAN DEFAULT NULL, tetanus_protected BOOLEAN DEFAULT NULL, isolation JSONB DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ed_admission_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, first_assessment_contact_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, assessment_start_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, assessment_end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, physician_target_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, first_physician_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, treatment_end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ed_discharge_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, admin_admission_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, admin_discharge_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, pre_inpatient_admission_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mts_category VARCHAR(16) DEFAULT NULL, mts_diagram VARCHAR(64) DEFAULT NULL, mts_indicator VARCHAR(64) DEFAULT NULL, mts_pain_score SMALLINT DEFAULT NULL, retriage_category VARCHAR(16) DEFAULT NULL, retriage_diagram VARCHAR(64) DEFAULT NULL, retriage_indicator VARCHAR(64) DEFAULT NULL, retriage_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, disposition_code VARCHAR(32) DEFAULT NULL, disposition_care_level VARCHAR(16) DEFAULT NULL, vitals_reference VARCHAR(32) DEFAULT NULL, vitals_measured_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, vitals_bp_systolic SMALLINT DEFAULT NULL, vitals_bp_diastolic SMALLINT DEFAULT NULL, vitals_heart_rate SMALLINT DEFAULT NULL, vitals_temperature NUMERIC(4, 1) DEFAULT NULL, vitals_respiratory_rate SMALLINT DEFAULT NULL, vitals_glucose SMALLINT DEFAULT NULL, vitals_spo2 SMALLINT DEFAULT NULL, vitals_gcs SMALLINT DEFAULT NULL, encounter_id INT NOT NULL, hospital_id INT NOT NULL, import_id INT NOT NULL, admitting_department_id INT DEFAULT NULL, PRIMARY KEY (encounter_id))');
        $this->addSql('INSERT INTO ed_visit (encounter_id, hospital_id, key_generation, created_at, visit_uid, visit_fingerprint, import_id, anchor_source, arrival_mode, arrival_route, referral_source, admission_type, case_status, work_accident, tetanus_protected, isolation, ed_admission_at, first_assessment_contact_at, assessment_start_at, assessment_end_at, physician_target_at, first_physician_at, treatment_end_at, ed_discharge_at, admin_admission_at, admin_discharge_at, pre_inpatient_admission_at, mts_category, mts_diagram, mts_indicator, mts_pain_score, retriage_category, retriage_diagram, retriage_indicator, retriage_at, disposition_code, disposition_care_level, admitting_department_id, vitals_reference, vitals_measured_at, vitals_bp_systolic, vitals_bp_diastolic, vitals_heart_rate, vitals_temperature, vitals_respiratory_rate, vitals_glucose, vitals_spo2, vitals_gcs) SELECT id, hospital_id, key_generation, created_at, visit_uid, visit_fingerprint, import_id, anchor_source, arrival_mode, arrival_route, referral_source, admission_type, case_status, work_accident, tetanus_protected, isolation, ed_admission_at, first_assessment_contact_at, assessment_start_at, assessment_end_at, physician_target_at, first_physician_at, treatment_end_at, ed_discharge_at, admin_admission_at, admin_discharge_at, pre_inpatient_admission_at, mts_category, mts_diagram, mts_indicator, mts_pain_score, retriage_category, retriage_diagram, retriage_indicator, retriage_at, disposition_code, disposition_care_level, admitting_department_id, vitals_reference, vitals_measured_at, vitals_bp_systolic, vitals_bp_diastolic, vitals_heart_rate, vitals_temperature, vitals_respiratory_rate, vitals_glucose, vitals_spo2, vitals_gcs FROM encounter');
        $this->addSql('CREATE INDEX idx_ed_visit_hospital_route ON ed_visit (hospital_id, arrival_route)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ed_visit_fingerprint ON ed_visit (hospital_id, key_generation, visit_fingerprint)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ed_visit_uid ON ed_visit (visit_uid)');
        $this->addSql('CREATE INDEX IDX_FA54BD4663DBB69 ON ed_visit (hospital_id)');
        $this->addSql('CREATE INDEX IDX_FA54BD46B6A263D9 ON ed_visit (import_id)');
        $this->addSql('CREATE INDEX IDX_FA54BD46BD6EC305 ON ed_visit (admitting_department_id)');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT FK_FA54BD46D6E2FADC FOREIGN KEY (encounter_id) REFERENCES encounter (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT FK_FA54BD4663DBB69 FOREIGN KEY (hospital_id) REFERENCES hospital (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT FK_FA54BD46B6A263D9 FOREIGN KEY (import_id) REFERENCES import (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT FK_FA54BD46BD6EC305 FOREIGN KEY (admitting_department_id) REFERENCES department (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT chk_ed_visit_key_generation CHECK (key_generation >= 1)');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT chk_ed_visit_arrival_route CHECK (arrival_route = CASE WHEN arrival_mode IN (\'ambulance\', \'patient_transport\', \'physician_vehicle\') THEN \'ems\' WHEN arrival_mode = \'self\' THEN \'self\' ELSE \'unknown\' END)');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT chk_ed_visit_care_level CHECK (disposition_care_level IS NULL OR disposition_code IN (\'admitted\', \'transferred_external\'))');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT chk_ed_visit_tetanus_protected CHECK (tetanus_protected IS NULL OR tetanus_protected)');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT chk_ed_visit_vitals_measured_at CHECK (vitals_measured_at IS NULL OR vitals_reference = \'admission_measurement\')');
        $this->addSql('ALTER TABLE ed_visit ADD CONSTRAINT chk_ed_visit_work_accident CHECK (work_accident IS NULL OR work_accident)');
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT chk_encounter_arrival_route');
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT chk_encounter_care_level');
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT chk_encounter_tetanus_protected');
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT chk_encounter_vitals_measured_at');
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT chk_encounter_work_accident');
        $this->addSql('DROP INDEX uniq_encounter_visit');
        $this->addSql('DROP INDEX uniq_encounter_visit_uid');
        $this->addSql('DROP INDEX idx_encounter_hospital_route_anchor');
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT fk_69d229cab6a263d9');
        $this->addSql('ALTER TABLE encounter DROP CONSTRAINT fk_69d229cabd6ec305');
        $this->addSql('ALTER TABLE encounter DROP visit_uid');
        $this->addSql('ALTER TABLE encounter DROP visit_fingerprint');
        $this->addSql('ALTER TABLE encounter DROP import_id');
        $this->addSql('ALTER TABLE encounter DROP anchor_source');
        $this->addSql('ALTER TABLE encounter DROP arrival_mode');
        $this->addSql('ALTER TABLE encounter DROP arrival_route');
        $this->addSql('ALTER TABLE encounter DROP referral_source');
        $this->addSql('ALTER TABLE encounter DROP admission_type');
        $this->addSql('ALTER TABLE encounter DROP case_status');
        $this->addSql('ALTER TABLE encounter DROP work_accident');
        $this->addSql('ALTER TABLE encounter DROP tetanus_protected');
        $this->addSql('ALTER TABLE encounter DROP isolation');
        $this->addSql('ALTER TABLE encounter DROP ed_admission_at');
        $this->addSql('ALTER TABLE encounter DROP first_assessment_contact_at');
        $this->addSql('ALTER TABLE encounter DROP assessment_start_at');
        $this->addSql('ALTER TABLE encounter DROP assessment_end_at');
        $this->addSql('ALTER TABLE encounter DROP physician_target_at');
        $this->addSql('ALTER TABLE encounter DROP first_physician_at');
        $this->addSql('ALTER TABLE encounter DROP treatment_end_at');
        $this->addSql('ALTER TABLE encounter DROP ed_discharge_at');
        $this->addSql('ALTER TABLE encounter DROP admin_admission_at');
        $this->addSql('ALTER TABLE encounter DROP admin_discharge_at');
        $this->addSql('ALTER TABLE encounter DROP pre_inpatient_admission_at');
        $this->addSql('ALTER TABLE encounter DROP mts_category');
        $this->addSql('ALTER TABLE encounter DROP mts_diagram');
        $this->addSql('ALTER TABLE encounter DROP mts_indicator');
        $this->addSql('ALTER TABLE encounter DROP mts_pain_score');
        $this->addSql('ALTER TABLE encounter DROP retriage_category');
        $this->addSql('ALTER TABLE encounter DROP retriage_diagram');
        $this->addSql('ALTER TABLE encounter DROP retriage_indicator');
        $this->addSql('ALTER TABLE encounter DROP retriage_at');
        $this->addSql('ALTER TABLE encounter DROP disposition_code');
        $this->addSql('ALTER TABLE encounter DROP disposition_care_level');
        $this->addSql('ALTER TABLE encounter DROP admitting_department_id');
        $this->addSql('ALTER TABLE encounter DROP vitals_reference');
        $this->addSql('ALTER TABLE encounter DROP vitals_measured_at');
        $this->addSql('ALTER TABLE encounter DROP vitals_bp_systolic');
        $this->addSql('ALTER TABLE encounter DROP vitals_bp_diastolic');
        $this->addSql('ALTER TABLE encounter DROP vitals_heart_rate');
        $this->addSql('ALTER TABLE encounter DROP vitals_temperature');
        $this->addSql('ALTER TABLE encounter DROP vitals_respiratory_rate');
        $this->addSql('ALTER TABLE encounter DROP vitals_glucose');
        $this->addSql('ALTER TABLE encounter DROP vitals_spo2');
        $this->addSql('ALTER TABLE encounter DROP vitals_gcs');
    }

    public function down(Schema $schema): void
    {
        // Encounters without ED visit (later data) cannot be expressed in the old model.
        $this->addSql('DELETE FROM encounter e WHERE NOT EXISTS (SELECT 1 FROM ed_visit v WHERE v.encounter_id = e.id)');
        $this->addSql('ALTER TABLE encounter ADD visit_uid UUID');
        $this->addSql('ALTER TABLE encounter ADD visit_fingerprint VARCHAR(64)');
        $this->addSql('ALTER TABLE encounter ADD import_id INT');
        $this->addSql('ALTER TABLE encounter ADD anchor_source VARCHAR(16)');
        $this->addSql('ALTER TABLE encounter ADD arrival_mode VARCHAR(32)');
        $this->addSql('ALTER TABLE encounter ADD arrival_route VARCHAR(16)');
        $this->addSql('ALTER TABLE encounter ADD referral_source VARCHAR(32)');
        $this->addSql('ALTER TABLE encounter ADD admission_type VARCHAR(16)');
        $this->addSql('ALTER TABLE encounter ADD case_status VARCHAR(16)');
        $this->addSql('ALTER TABLE encounter ADD work_accident BOOLEAN');
        $this->addSql('ALTER TABLE encounter ADD tetanus_protected BOOLEAN');
        $this->addSql('ALTER TABLE encounter ADD isolation JSONB DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE encounter ADD ed_admission_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD first_assessment_contact_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD assessment_start_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD assessment_end_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD physician_target_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD first_physician_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD treatment_end_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD ed_discharge_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD admin_admission_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD admin_discharge_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD pre_inpatient_admission_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD mts_category VARCHAR(16)');
        $this->addSql('ALTER TABLE encounter ADD mts_diagram VARCHAR(64)');
        $this->addSql('ALTER TABLE encounter ADD mts_indicator VARCHAR(64)');
        $this->addSql('ALTER TABLE encounter ADD mts_pain_score SMALLINT');
        $this->addSql('ALTER TABLE encounter ADD retriage_category VARCHAR(16)');
        $this->addSql('ALTER TABLE encounter ADD retriage_diagram VARCHAR(64)');
        $this->addSql('ALTER TABLE encounter ADD retriage_indicator VARCHAR(64)');
        $this->addSql('ALTER TABLE encounter ADD retriage_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD disposition_code VARCHAR(32)');
        $this->addSql('ALTER TABLE encounter ADD disposition_care_level VARCHAR(16)');
        $this->addSql('ALTER TABLE encounter ADD admitting_department_id INT');
        $this->addSql('ALTER TABLE encounter ADD vitals_reference VARCHAR(32)');
        $this->addSql('ALTER TABLE encounter ADD vitals_measured_at TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE encounter ADD vitals_bp_systolic SMALLINT');
        $this->addSql('ALTER TABLE encounter ADD vitals_bp_diastolic SMALLINT');
        $this->addSql('ALTER TABLE encounter ADD vitals_heart_rate SMALLINT');
        $this->addSql('ALTER TABLE encounter ADD vitals_temperature NUMERIC(4, 1)');
        $this->addSql('ALTER TABLE encounter ADD vitals_respiratory_rate SMALLINT');
        $this->addSql('ALTER TABLE encounter ADD vitals_glucose SMALLINT');
        $this->addSql('ALTER TABLE encounter ADD vitals_spo2 SMALLINT');
        $this->addSql('ALTER TABLE encounter ADD vitals_gcs SMALLINT');
        $this->addSql('UPDATE encounter e SET visit_uid = v.visit_uid, visit_fingerprint = v.visit_fingerprint, import_id = v.import_id, anchor_source = v.anchor_source, arrival_mode = v.arrival_mode, arrival_route = v.arrival_route, referral_source = v.referral_source, admission_type = v.admission_type, case_status = v.case_status, work_accident = v.work_accident, tetanus_protected = v.tetanus_protected, isolation = v.isolation, ed_admission_at = v.ed_admission_at, first_assessment_contact_at = v.first_assessment_contact_at, assessment_start_at = v.assessment_start_at, assessment_end_at = v.assessment_end_at, physician_target_at = v.physician_target_at, first_physician_at = v.first_physician_at, treatment_end_at = v.treatment_end_at, ed_discharge_at = v.ed_discharge_at, admin_admission_at = v.admin_admission_at, admin_discharge_at = v.admin_discharge_at, pre_inpatient_admission_at = v.pre_inpatient_admission_at, mts_category = v.mts_category, mts_diagram = v.mts_diagram, mts_indicator = v.mts_indicator, mts_pain_score = v.mts_pain_score, retriage_category = v.retriage_category, retriage_diagram = v.retriage_diagram, retriage_indicator = v.retriage_indicator, retriage_at = v.retriage_at, disposition_code = v.disposition_code, disposition_care_level = v.disposition_care_level, admitting_department_id = v.admitting_department_id, vitals_reference = v.vitals_reference, vitals_measured_at = v.vitals_measured_at, vitals_bp_systolic = v.vitals_bp_systolic, vitals_bp_diastolic = v.vitals_bp_diastolic, vitals_heart_rate = v.vitals_heart_rate, vitals_temperature = v.vitals_temperature, vitals_respiratory_rate = v.vitals_respiratory_rate, vitals_glucose = v.vitals_glucose, vitals_spo2 = v.vitals_spo2, vitals_gcs = v.vitals_gcs FROM ed_visit v WHERE v.encounter_id = e.id');
        $this->addSql('ALTER TABLE encounter ALTER visit_uid SET NOT NULL');
        $this->addSql('ALTER TABLE encounter ALTER visit_fingerprint SET NOT NULL');
        $this->addSql('ALTER TABLE encounter ALTER import_id SET NOT NULL');
        $this->addSql('ALTER TABLE encounter ALTER anchor_source SET NOT NULL');
        $this->addSql('ALTER TABLE encounter ALTER arrival_route SET NOT NULL');
        $this->addSql('ALTER TABLE encounter ALTER isolation SET NOT NULL');
        $this->addSql('DROP TABLE ed_visit');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_visit ON encounter (hospital_id, key_generation, visit_fingerprint)');
        $this->addSql('CREATE UNIQUE INDEX uniq_encounter_visit_uid ON encounter (visit_uid)');
        $this->addSql('CREATE INDEX idx_encounter_hospital_route_anchor ON encounter (hospital_id, arrival_route, anchor_at)');
        $this->addSql('CREATE INDEX idx_69d229cab6a263d9 ON encounter (import_id)');
        $this->addSql('CREATE INDEX idx_69d229cabd6ec305 ON encounter (admitting_department_id)');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT fk_69d229cab6a263d9 FOREIGN KEY (import_id) REFERENCES import (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT fk_69d229cabd6ec305 FOREIGN KEY (admitting_department_id) REFERENCES department (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT chk_encounter_arrival_route CHECK (arrival_route = CASE WHEN arrival_mode IN (\'ambulance\', \'patient_transport\', \'physician_vehicle\') THEN \'ems\' WHEN arrival_mode = \'self\' THEN \'self\' ELSE \'unknown\' END)');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT chk_encounter_care_level CHECK (disposition_care_level IS NULL OR disposition_code IN (\'admitted\', \'transferred_external\'))');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT chk_encounter_tetanus_protected CHECK (tetanus_protected IS NULL OR tetanus_protected)');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT chk_encounter_vitals_measured_at CHECK (vitals_measured_at IS NULL OR vitals_reference = \'admission_measurement\')');
        $this->addSql('ALTER TABLE encounter ADD CONSTRAINT chk_encounter_work_accident CHECK (work_accident IS NULL OR work_accident)');
    }
}
