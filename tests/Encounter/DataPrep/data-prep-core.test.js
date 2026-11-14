// Tests of the data preparation tool core (#1051). Run with `npm run test:js`.
// The sample file is synthetic (invented values, ORBIS-like layout); never use real exports here.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

import '../../../src/Encounter/UI/DataPrep/js/data-prep-core.js';

const prep = globalThis.EncounterDataPrep;
const base = new URL('../../../src/Encounter/UI/DataPrep/', import.meta.url);
const fixtures = new URL('./fixtures/', import.meta.url);
const json = (url) => JSON.parse(readFileSync(url, 'utf8'));

const profile = json(new URL('profiles/orbis-mts.json', base));
const schema = json(new URL('schema/ed_visit.v1.json', base));
const bundledCatalog = json(
    new URL('../../../src/Encounter/Application/Catalog/mts-de.json', import.meta.url),
);
// The bundled copy in the tool's catalog format, as DataPrepToolRenderer passes it (MtsCatalog::bundledToolCatalog()).
const toolCatalog = (data, aliases = { diagrams: {}, indicators: {} }) =>
    prep.parseCatalog({
        format: 'encounter-mts-catalog',
        version: 1,
        id: data.id,
        diagrams: data.diagrams.map((label) => ({ code: prep.catalogCode(label), name: label })),
        indicators: data.indicators.map((label) => ({
            code: prep.catalogCode(label),
            name: label,
        })),
        aliases,
    });
const catalogs = { 'mts-de': toolCatalog(bundledCatalog) };
const vectors = json(new URL('fingerprint-vectors.json', fixtures));
const sampleBytes = readFileSync(new URL('orbis-mts-sample.csv', fixtures));
const localMapJson = readFileSync(new URL('local-map.sample.json', fixtures), 'utf8');

const keyFile = (hex = vectors.key) => ({
    format: 'encounter-key',
    version: 1,
    label: 'Test',
    created: '2026-11-12',
    key: hex,
    check: hex === vectors.key ? vectors.keyCheck : undefined,
});

async function run({
    bytes = sampleBytes,
    key,
    localMap = prep.loadLocalMap(localMapJson, profile),
    edit,
    catalogs: runCatalogs = catalogs,
} = {}) {
    const { text } = prep.decodeBytes(bytes);
    const table = prep.readTable(text, profile.source.delimiter);
    edit?.(table);
    return prep.transform(table, {
        profile,
        schema,
        key: key ?? (await prep.loadKey(keyFile())),
        localMap,
        catalogs: runCatalogs,
    });
}

function byField(result, row) {
    return Object.fromEntries(
        result.header.map((field, index) => [field, result.rows[row][index]]),
    );
}

function setCell(table, column, row, value) {
    table.rows[row][table.header.indexOf(column)] = value;
}

describe('reading the source file', () => {
    it('reads cp1252 and UTF-8 alike and detects the ORBIS profile', () => {
        const cp1252 = prep.decodeBytes(sampleBytes);
        assert.equal(cp1252.encoding, 'windows-1252');
        const utf8 = prep.decodeBytes(new TextEncoder().encode(cp1252.text));
        assert.equal(utf8.encoding, 'utf-8');
        assert.equal(utf8.text, cp1252.text);

        const table = prep.readTable(cp1252.text, ';');
        assert.equal(table.rows.length, 8);
        assert.ok(table.header.includes('Erstkontakt Ersteinschätzung'));
        assert.equal(table.header.at(-1), '', 'ORBIS writes a trailing empty column');
        assert.equal(prep.detectProfile(table.header, [profile]), profile);
        assert.equal(prep.detectProfile(['Fallnummer', 'Alter'], [profile]), null);
    });

    it('parses quoted cells with delimiters, quotes and line breaks', () => {
        assert.deepEqual(prep.parseCsv('a;b\r\n"x;1";"say ""hi"""\n"multi\nline";\n'), [
            ['a', 'b'],
            ['x;1', 'say "hi"'],
            ['multi\nline', ''],
        ]);
    });

    it('parses ORBIS timestamps with two spaces and optional seconds', () => {
        const ts = prep.parseSourceTimestamp('05.10.2026  08:17:05');
        assert.equal(prep.formatMinute(ts), '2026-10-05T08:17');
        assert.equal(prep.formatSecond(ts), '2026-10-05T08:17:05');
        assert.equal(
            prep.formatSecond(prep.parseSourceTimestamp('01.01.2027 00:00')),
            '2027-01-01T00:00:00',
        );
        assert.equal(prep.parseSourceTimestamp('31.02.2026  10:00:00'), null);
        assert.equal(prep.parseSourceTimestamp('05.10.2026'), null, 'a date alone is no timestamp');
    });
});

describe('column preview', () => {
    it('keeps, transforms and drops columns by the allow-list', () => {
        const { text } = prep.decodeBytes(sampleBytes);
        const plan = new Map(
            prep.columnPlan(prep.readTable(text).header, profile).map((c) => [c.column, c]),
        );

        for (const column of [
            'Patientenname',
            'Fall-ID',
            'Bemerkung',
            'Grund des Kommens',
            'VerantwortlicherArzt',
        ]) {
            assert.equal(plan.get(column).status, 'dropped', column);
        }
        assert.deepEqual(plan.get('Geburtsdatum').usedFor, ['age']);
        assert.deepEqual(plan.get('Stat_Station').usedFor, ['care_level']);
        assert.equal(plan.get('Aufnahme ZNA bis Erstkontakt Arzt').reason, 'derived');
        assert.equal(plan.get('Aufnahme ZNA').status, 'transformed', 'anchor of the fingerprint');
        assert.equal(plan.get('Behandlungsende').status, 'kept');
        assert.deepEqual(plan.get('Blutdruck').targets, ['bp_systolic', 'bp_diastolic']);
        assert.equal(plan.get('BegruendungHerabtriagierung').reason, 'freeText');
        assert.deepEqual(plan.get('ZweiteinschaetzungDiagramm').targets, ['retriage_diagram']);
        const repeated = prep
            .columnPlan(prep.readTable(text).header, profile)
            .filter((c) => c.column === 'Zweiteinschätzung');
        assert.equal(repeated.length, 2, 'the repeated ORBIS header is listed per column');
        assert.ok(repeated.every((c) => c.status === 'dropped'));
        assert.equal(plan.get('Fallnummer').status, 'transformed');
        assert.ok(!plan.has(''), 'the trailing empty column is not listed');
    });
});

describe('transformation of the synthetic sample', () => {
    it('writes the exact ed_visit v1 header', async () => {
        const result = await run();
        assert.deepEqual(
            result.header,
            schema.fields.map((field) => field.name),
        );
        assert.equal(result.header.length, 43);
    });

    it('maps the first visit field by field', async () => {
        const row = byField(await run(), 0);
        assert.deepEqual(row, {
            visit_fingerprint: vectors.vectors[0].visitFingerprint,
            case_key: vectors.vectors[0].caseKey,
            age: '76',
            sex: 'F',
            arrival_mode: 'ambulance',
            referral_source: 'ems',
            admission_type: 'emergency',
            case_status: 'inpatient',
            ed_admission_at: '2026-10-05T08:15',
            first_assessment_contact_at: '2026-10-05T08:17',
            assessment_start_at: '2026-10-05T08:17',
            assessment_end_at: '2026-10-05T08:21',
            physician_target_at: '2026-10-05T08:47',
            first_physician_at: '2026-10-05T08:40',
            treatment_end_at: '2026-10-05T11:02',
            ed_discharge_at: '2026-10-05T11:30',
            admin_admission_at: '2026-10-05T11:00',
            admin_discharge_at: '',
            pre_inpatient_admission_at: '',
            mts_category: 'yellow',
            mts_diagram: 'abdominelle_schmerzen_bei_erwachsenen',
            mts_indicator: 'maessiger_schmerz',
            pain_score: '5',
            retriage_category: 'orange',
            retriage_diagram: 'abdominelle_schmerzen_bei_erwachsenen',
            retriage_indicator: 'kardialer_schmerz',
            retriage_at: '',
            disposition: 'admitted',
            care_level: 'icu',
            admitting_department: 'Innere Medizin',
            vitals_reference: 'documented_initial',
            vitals_measured_at: '',
            bp_systolic: '145',
            bp_diastolic: '85',
            heart_rate: '92',
            temperature: '37.8',
            respiratory_rate: '18',
            glucose: '100',
            spo2: '96',
            gcs: '15',
            work_accident: '',
            tetanus_protected: '1',
            isolation: 'mrsa',
        });
    });

    it('uses the admission as fallback anchor, clamps age and empties implausible vitals', async () => {
        const row = byField(await run(), 1);
        assert.equal(row.visit_fingerprint, vectors.vectors[2].visitFingerprint);
        assert.equal(row.first_assessment_contact_at, '');
        assert.equal(row.ed_admission_at, '2026-10-06T23:59', 'seconds are cut, not rounded');
        assert.equal(row.age, '99');
        assert.equal(row.arrival_mode, 'self');
        assert.equal(row.disposition, 'home');
        assert.equal(row.care_level, '', 'care level only for admissions and transfers');
        assert.equal(row.bp_systolic, '120');
        assert.equal(row.bp_diastolic, '', '`120/? mmHg` has no diastolic value');
        assert.equal(row.heart_rate, '', '350 /min is out of range');
        assert.equal(row.glucose, '99', '99.49 mg/dl rounds half up to 99');
        assert.equal(row.vitals_reference, 'documented_initial');
        assert.equal(row.isolation, '');
    });

    it('derives age from the date of birth and keeps one case key for two visits', async () => {
        const result = await run();
        const first = byField(result, 0);
        const third = byField(result, 2);
        assert.equal(third.visit_fingerprint, vectors.vectors[1].visitFingerprint);
        assert.equal(third.case_key, first.case_key, 'non-digits are removed from the case number');
        assert.notEqual(third.visit_fingerprint, first.visit_fingerprint);
        assert.equal(third.age, '26', 'born 15.06.2000, anchor 20.10.2026');
        assert.equal(third.temperature, '36.4', 'decimal comma');
        assert.equal(third.pre_inpatient_admission_at, '2026-10-20T09:00');
        assert.equal(third.admission_type, 'elective');
        assert.equal(third.referral_source, 'physician');
        assert.equal(
            third.mts_indicator,
            'kuerzlich_aufgetretenes_problem',
            'unlisted indicators keep a code',
        );
        assert.equal(third.disposition, 'to_physician');
    });

    it('maps external transfers, code sets, flags and prefixes', async () => {
        const result = await run();
        const transfer = byField(result, 3);
        assert.equal(transfer.age, '1');
        assert.equal(transfer.arrival_mode, 'patient_transport');
        assert.equal(transfer.disposition, 'transferred_external');
        assert.equal(
            transfer.care_level,
            'monitoring',
            'the internal station map does not apply to transfers',
        );
        assert.equal(transfer.isolation, 'covid19,mdro');
        assert.equal(transfer.work_accident, '1');

        const unmapped = byField(result, 4);
        assert.equal(unmapped.referral_source, 'kv_practice');
        assert.equal(unmapped.arrival_mode, 'physician_vehicle');
        assert.equal(unmapped.mts_indicator, '', '`-- keine --` is empty');
        assert.equal(unmapped.disposition, '');
        assert.equal(unmapped.admitting_department, '');
        assert.equal(unmapped.temperature, '');
        assert.equal(unmapped.vitals_reference, '', 'no vital value, no reference');

        const operation = byField(result, 5);
        assert.equal(operation.disposition, 'admitted');
        assert.equal(
            operation.care_level,
            'or',
            'unmapped station keeps the level of the disposition text',
        );
        assert.equal(operation.admitting_department, 'Unfallchirurgie');
        assert.equal(operation.pain_score, '');
        assert.equal(
            operation.mts_diagram,
            'erfundenes_diagramm',
            'unknown diagrams keep a code, the server creates a provisional entry (#1157)',
        );
    });

    it('reports skipped rows, emptied vitals and unmapped values', async () => {
        const { report, content } = await run();
        assert.equal(report.rowsRead, 8);
        assert.equal(report.rowsWritten, 6);
        assert.deepEqual(report.skipped, {
            noCaseNumber: 1,
            noAnchor: 1,
            noAge: 0,
            noSex: 0,
            duplicate: 0,
        });
        assert.deepEqual(report.anchorSources, { assessment: 5, admission: 1 });
        assert.equal(report.ageFromBirthDate, 1);
        assert.equal(report.ageClamped, 2);
        assert.deepEqual(report.vitalsEmptied, {
            heart_rate: { outOfRange: 1 },
            temperature: { outOfRange: 1 },
        });
        assert.deepEqual(report.invalidValues, { pain_score: { outOfRange: 1 } });
        assert.deepEqual(report.unmapped, {
            disposition: { 'Unbekannter Entlassungstext': 1 },
            admitting_department: { 'KAR TEST': 1 },
            care_level: { 'S9 TEST': 1 },
        });
        assert.deepEqual(report.unlistedCatalogValues, {
            mts_diagram: { 'Erfundenes Diagramm': 1 },
            mts_indicator: { 'Kürzlich aufgetretenes Problem': 1 },
        });
        assert.deepEqual(report.catalogAliases, {});
        assert.equal(report.firstAnchor, '2026-10-05T08:17');
        assert.equal(report.lastAnchor, '2026-10-20T10:04');
        assert.equal(content.passed, true);
    });

    it('gives unknown care level and no department without a local map', async () => {
        const result = await run({ localMap: prep.emptyLocalMap() });
        const first = byField(result, 0);
        assert.equal(first.care_level, 'ward', 'level of the disposition text without station map');
        assert.equal(first.admitting_department, '');
        assert.equal(result.report.unmapped.admitting_department['INN TEST'], 1);
    });
});

describe('MTS catalog (#1157)', () => {
    // Row 6 of the sample (index 5) has the diagram `Erfundenes Diagramm`, row 3 (index 2) the indicator
    // `Kürzlich aufgetretenes Problem`; neither is in the bundled copy.
    const serverCatalog = (aliases) =>
        prep.parseCatalog({
            format: 'encounter-mts-catalog',
            version: 1,
            id: 'mts-de',
            diagrams: [
                {
                    code: 'abdominelle_schmerzen_bei_erwachsenen',
                    name: 'Abdominelle Schmerzen bei Erwachsenen',
                    englishName: 'Abdominal pain in adults',
                },
            ],
            indicators: [],
            aliases,
        });

    it('keeps the source code of an alias and lists it as another spelling', async () => {
        const result = await run({
            catalogs: {
                'mts-de': serverCatalog({
                    diagrams: { erfundenes_diagramm: 'abdominelle_schmerzen_bei_erwachsenen' },
                    indicators: {},
                }),
            },
        });
        assert.equal(
            byField(result, 5).mts_diagram,
            'erfundenes_diagramm',
            'the code as sent, not the target',
        );
        assert.deepEqual(result.report.catalogAliases, {
            mts_diagram: { 'Erfundenes Diagramm → abdominelle_schmerzen_bei_erwachsenen': 1 },
        });
        assert.equal(
            result.report.unlistedCatalogValues.mts_diagram['Erfundenes Diagramm'],
            undefined,
        );
    });

    it('knows entries by code, also for a provisional entry whose name is its code', async () => {
        const catalog = serverCatalog({ diagrams: {}, indicators: {} });
        catalog.indicators.push({
            code: 'kuerzlich_aufgetretenes_problem',
            name: 'kuerzlich_aufgetretenes_problem',
            englishName: null,
        });
        const result = await run({ catalogs: { 'mts-de': catalog } });
        assert.equal(byField(result, 2).mts_indicator, 'kuerzlich_aufgetretenes_problem');
        assert.equal(
            result.report.unlistedCatalogValues.mts_indicator['Kürzlich aufgetretenes Problem'],
            undefined,
        );
    });

    it('accepts the catalog format and rejects anything else', () => {
        const catalog = serverCatalog([]);
        assert.deepEqual(
            catalog.aliases,
            { diagrams: {}, indicators: {} },
            'empty PHP arrays arrive as []',
        );
        assert.equal(catalog.diagrams[0].englishName, 'Abdominal pain in adults');
        for (const payload of [
            null,
            { format: 'other', version: 1, id: 'mts-de', diagrams: [], indicators: [] },
            {
                format: 'encounter-mts-catalog',
                version: 2,
                id: 'mts-de',
                diagrams: [],
                indicators: [],
            },
            { format: 'encounter-mts-catalog', version: 1, id: 'mts-de', diagrams: [] },
            {
                format: 'encounter-mts-catalog',
                version: 1,
                id: 'mts-de',
                diagrams: [{ code: 'Not A Code', name: 'x' }],
                indicators: [],
            },
            {
                format: 'encounter-mts-catalog',
                version: 1,
                id: 'mts-de',
                diagrams: [],
                indicators: [],
                aliases: { diagrams: { a: 'B!' } },
            },
        ]) {
            assert.throws(() => prep.parseCatalog(payload), Error, JSON.stringify(payload));
        }
    });
});

describe('fingerprints', () => {
    it('match the PHP reference vectors', async () => {
        const key = await prep.loadKey(keyFile());
        for (const vector of vectors.vectors) {
            const caseNumber = prep.caseNumberOf(vector.caseNumber);
            const anchor = prep.parseSourceTimestamp(vector.anchor);
            const input = prep.fingerprintInput(caseNumber, vector.anchorSource, anchor);
            assert.equal(input, vector.input);
            assert.equal(await prep.hmacHex(key.cryptoKey, input), vector.visitFingerprint);
            assert.equal(
                await prep.hmacHex(key.cryptoKey, `case:v1|${caseNumber}`),
                vector.caseKey,
            );
        }
    });

    it('are stable for the same input and key and change with the key', async () => {
        const first = await run();
        const second = await run();
        const column = (result) => result.rows.map((row) => row[0]);
        assert.deepEqual(column(second), column(first));

        const other = await prep.loadKey(await prep.generateKeyFile('Other', '2026-11-12'));
        const third = await run({ key: other });
        assert.equal(third.rows.length, first.rows.length);
        third.rows.forEach((row, index) => assert.notEqual(row[0], first.rows[index][0]));
    });

    it('skips a second row with the same fingerprint', async () => {
        const result = await run({ edit: (table) => table.rows.push([...table.rows[0]]) });
        assert.equal(result.report.skipped.duplicate, 1);
        assert.equal(result.rows.length, 6);
    });
});

describe('key file', () => {
    it('generates a 256-bit key with a matching check value', async () => {
        const file = await prep.generateKeyFile('  Team ZNA ', '2026-11-12');
        assert.equal(file.format, 'encounter-key');
        assert.equal(file.label, 'Team ZNA');
        assert.match(file.key, /^[0-9a-f]{64}$/);
        const key = await prep.loadKey(JSON.stringify(file));
        assert.equal(key.check, file.check);
    });

    it('computes the documented check value and rejects tampered files', async () => {
        const key = await prep.loadKey(keyFile());
        assert.equal(key.check, vectors.keyCheck);
        await assert.rejects(
            prep.loadKey({ ...keyFile(), check: 'ab'.repeat(32) }),
            /check-mismatch/,
        );
        await assert.rejects(prep.loadKey({ ...keyFile(), key: 'abcd' }), /invalid-key/);
        await assert.rejects(prep.loadKey({ format: 'other' }), /not-a-key-file/);
    });
});

describe('local map', () => {
    it('maps departments to names of the reference catalog', () => {
        const map = (departments) => ({ format: 'encounter-local-map', version: 1, departments });
        const loaded = prep.loadLocalMap(
            map({ 'GCH X': 'Gefäßchirurgie', 'ACH X': 'Allgemein- und Viszeralchirurgie' }),
            profile,
        );
        assert.equal(loaded.departments.get('gch x'), 'Gefäßchirurgie');
        for (const invalid of [
            'Chirurgie, Allgemein',
            'Chirurgie,Allgemein',
            'Station Dr. Muster',
            'Neu ab 01.01.2027',
            'a"b',
            'x'.repeat(65),
            'tab\there',
            ' Leading',
            '',
            42,
        ]) {
            assert.throws(
                () => prep.loadLocalMap(map({ X: invalid }), profile),
                /invalid-local-map/,
                String(invalid),
            );
        }
    });

    it('rejects unknown care levels, invalid department names and dispositions', () => {
        const map = (data) => ({ format: 'encounter-local-map', version: 1, ...data });
        assert.throws(
            () => prep.loadLocalMap(map({ stations: { ITS: 'intensive' } }), profile),
            /invalid-local-map/,
        );
        assert.throws(
            () => prep.loadLocalMap(map({ departments: { INN: 'a;b' } }), profile),
            /invalid-local-map/,
        );
        assert.throws(
            () =>
                prep.loadLocalMap(
                    map({ dispositions: { X: { disposition: 'home', careLevel: 'icu' } } }),
                    profile,
                ),
            /invalid-local-map/,
        );
        assert.throws(
            () => prep.loadLocalMap(map({ profile: 'other' }), profile),
            /profile-mismatch/,
        );
    });

    it('lets hospital disposition texts override the public profile', async () => {
        const localMap = prep.loadLocalMap(
            {
                format: 'encounter-local-map',
                version: 1,
                dispositions: { 'Unbekannter Entlassungstext': { disposition: 'died' } },
            },
            profile,
        );
        const row = byField(await run({ localMap }), 4);
        assert.equal(row.disposition, 'died');
    });
});

describe('local map editor', () => {
    it('turns unmapped values into a saved map that validates on reload', async () => {
        const before = await run({ localMap: prep.emptyLocalMap() });
        const sections = prep.localMapSections(profile);
        assert.deepEqual(sections, {
            disposition: 'dispositions',
            care_level: 'stations',
            admitting_department: 'departments',
        });
        assert.equal(before.report.unmapped.admitting_department['INN TEST'], 1);
        assert.equal(before.report.unmapped.care_level['ITS TEST'], 1);
        assert.equal(before.report.unmapped.disposition['Unbekannter Entlassungstext'], 1);

        // What the editor does with the filled-in report rows, in the tool's order.
        let data = prep.newLocalMapData(profile, 'Synthetic test hospital');
        data = prep.setLocalMapEntry(data, 'departments', 'INN TEST', 'Innere Medizin');
        data = prep.setLocalMapEntry(data, 'stations', 'ITS TEST', 'icu');
        data = prep.setLocalMapEntry(data, 'dispositions', 'Unbekannter Entlassungstext', {
            disposition: 'transferred_external',
            careLevel: 'icu',
        });
        const saved = prep.serializeLocalMap(data);
        assert.ok(saved.endsWith('}\n'));
        assert.deepEqual(JSON.parse(saved), {
            format: 'encounter-local-map',
            version: 1,
            profile: 'orbis-mts',
            hospital: 'Synthetic test hospital',
            departments: { 'INN TEST': 'Innere Medizin' },
            stations: { 'ITS TEST': 'icu' },
            dispositions: {
                'Unbekannter Entlassungstext': {
                    disposition: 'transferred_external',
                    careLevel: 'icu',
                },
            },
        });

        const reloaded = prep.loadLocalMap(saved, profile);
        assert.deepEqual(reloaded.counts, { departments: 1, stations: 1, dispositions: 1 });
        assert.deepEqual(reloaded.data, JSON.parse(saved));

        const after = await run({ localMap: reloaded });
        assert.equal(after.report.unmapped.admitting_department['INN TEST'], undefined);
        assert.equal(after.report.unmapped.admitting_department['UCH TEST'], 1);
        assert.equal(after.report.unmapped.care_level['ITS TEST'], undefined);
        assert.equal(after.report.unmapped.disposition, undefined);
        const first = byField(after, 0);
        assert.equal(first.admitting_department, 'Innere Medizin');
        assert.equal(first.care_level, 'icu', 'station map overrides the level of the text');
        const fifth = byField(after, 4);
        assert.equal(fifth.disposition, 'transferred_external');
        assert.equal(fifth.care_level, 'icu');
    });

    it('adds to a loaded map, replacing entries with the same lookup key', () => {
        const loaded = prep.loadLocalMap(localMapJson, profile);
        let data = prep.setLocalMapEntry(loaded.data, 'departments', 'inn  test', 'Kardiologie');
        data = prep.setLocalMapEntry(data, 'stations', 'S1 TEST', null);
        assert.deepEqual(data.departments, {
            'UCH TEST': 'Unfallchirurgie',
            'inn test': 'Kardiologie',
        });
        assert.deepEqual(data.stations, { 'ITS TEST': 'icu' });
        assert.equal(data.hospital, 'Synthetic test hospital');
        assert.deepEqual(
            loaded.data.departments,
            { 'INN TEST': 'Innere Medizin', 'UCH TEST': 'Unfallchirurgie' },
            'the loaded map is not changed',
        );
        assert.equal(prep.loadLocalMap(data, profile).departments.get('inn test'), 'Kardiologie');
    });

    it('validates edited entries like a loaded map', () => {
        const base = prep.newLocalMapData(profile);
        for (const [section, target, entry] of [
            ['departments', 'Chirurgie, Allgemein', 'departments.X'],
            ['departments', 'Station Dr. Muster', 'departments.X'],
            ['stations', 'intensive', 'stations.X'],
            ['dispositions', { disposition: 'home', careLevel: 'icu' }, 'dispositions.X'],
            ['dispositions', { disposition: 'unknown' }, 'dispositions.X'],
        ]) {
            const data = prep.setLocalMapEntry(base, section, 'X', target);
            assert.throws(
                () => prep.loadLocalMap(data, profile),
                (error) => error.message === 'invalid-local-map' && error.entries[0] === entry,
                JSON.stringify(target),
            );
        }
        assert.equal(prep.isDepartmentName('Innere Medizin'), true);
        assert.equal(prep.isDepartmentName('Chirurgie, Allgemein'), false);
        assert.throws(() => prep.setLocalMapEntry(base, 'other', 'X', 'Y'), /Unknown local map/);
    });

    it('loads the template and the example offered on the landing page', () => {
        for (const variant of ['template', 'example']) {
            const content = readFileSync(new URL(`local-map/${variant}.json`, base), 'utf8');
            const loaded = prep.loadLocalMap(content, profile);
            assert.equal(loaded.data.profile, profile.id, variant);
        }
    });
});

describe('content check', () => {
    it('fails for a name-like column feeding the output', async () => {
        const result = await run({
            edit: (table) => setCell(table, 'MTS Diagramm', 0, 'Mustermann, Erika'),
        });
        assert.equal(result.content.passed, false);
        assert.deepEqual(result.content.findings, [
            { field: 'mts_diagram', column: 'MTS Diagramm', kind: 'name', count: 1, firstLine: 2 },
        ]);
    });

    it('finds date-like values and long free text in non-date fields', async () => {
        const result = await run({
            edit: (table) => {
                setCell(table, 'Indikator', 0, 'geboren 01.02.1950');
                setCell(
                    table,
                    'MTS Diagramm',
                    2,
                    'Patient kommt seit drei Tagen mit starken Schmerzen im linken Bein und kann nicht mehr gehen',
                );
            },
        });
        const kinds = result.content.findings.map((finding) => `${finding.field}:${finding.kind}`);
        assert.ok(kinds.includes('mts_indicator:date'));
        assert.ok(kinds.includes('mts_diagram:freeText'));
    });

    it('does not flag the labels of the MTS catalog', () => {
        for (const label of [...bundledCatalog.diagrams, ...bundledCatalog.indicators]) {
            assert.deepEqual(prep.contentFindings(label), [], label);
        }
    });
});

describe('exchange file', () => {
    it('starts with the manifest and the header', async () => {
        const result = await run();
        const file = prep.buildExchangeFile(result, {
            profile,
            schema,
            toolVersion: '1.0.0',
            keyCheck: vectors.keyCheck,
        });
        const lines = file.split('\n');
        assert.equal(
            lines[0],
            `#encounter-exchange;schema=ed_visit;schema_version=1;profile=orbis-mts;profile_version=1;tool=1.0.0;key_check=${vectors.keyCheck};fingerprint=ed-visit:v1;rows=6`,
        );
        assert.equal(lines[1], schema.fields.map((field) => field.name).join(';'));
        assert.equal(lines.length, 2 + 6 + 1, 'manifest, header, six rows, final newline');
        assert.ok(lines.slice(2, 8).every((line) => line.split(';').length === 43));
        assert.equal(
            prep.exchangeFileName(result, profile),
            'ed_visit_orbis-mts_2026-10-05_2026-10-20.csv',
        );
    });

    it('never writes dropped source values', async () => {
        const result = await run();
        const file = prep.buildExchangeFile(result, {
            profile,
            schema,
            toolVersion: '1.0.0',
            keyCheck: 'x',
        });
        for (const secret of [
            'Testperson',
            'Beispiel',
            '1950',
            '00012345',
            'Freitext',
            'ITS TEST',
            'Arzt, Test',
        ]) {
            assert.ok(!file.includes(secret), secret);
        }
    });
});

describe('versions', () => {
    it('compares versions and derives the update state', () => {
        assert.equal(prep.compareVersions('1.10.0', '1.9.9'), 1);
        assert.equal(prep.compareVersions('1.0', '1.0.0'), 0);
        assert.equal(
            prep.updateState('1.0.0', { version: '1.0.0', minVersion: '1.0.0' }),
            'current',
        );
        assert.equal(
            prep.updateState('1.0.0', { version: '1.1.0', minVersion: '1.0.0' }),
            'update',
        );
        assert.equal(
            prep.updateState('1.0.0', { version: '1.2.0', minVersion: '1.1.0' }),
            'blocked',
        );
    });

    it('cuts catalog codes at 64 characters without a trailing underscore', () => {
        const code = prep.catalogCode(`${'a'.repeat(63)} b`);
        assert.equal(code, 'a'.repeat(63));
        assert.match(
            prep.catalogCode(
                'Veränderter Bewusstseinszustand vollständig durch Alkoholgenuss erklärbar',
            ),
            /^[a-z0-9_]{1,64}$/,
        );
    });

    it('rounds half up without floating point errors', () => {
        assert.equal(prep.roundHalfUp('99.5'), '100');
        assert.equal(prep.roundHalfUp('99.49'), '99');
        assert.equal(prep.roundHalfUp('999.99'), '1000');
        assert.equal(prep.roundHalfUp('37.25', 1), '37.3');
        assert.equal(prep.roundHalfUp('0.04', 1), '0.0');
    });
});
