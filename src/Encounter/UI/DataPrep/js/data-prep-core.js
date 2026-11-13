/*
 * Core of the encounter data preparation tool (ADR 026, ADR 027, #1051).
 *
 * Plain script without imports or exports: the server inlines it into the offline HTML file, and
 * the Node tests load it as a side-effect module. Everything is published as
 * `globalThis.EncounterDataPrep`. No DOM access and no network access here.
 *
 * Rules: docs/04-features/encounter/exchange-format.md and data-preparation-tool.md.
 */
(function (root) {
    'use strict';

    const FINGERPRINT_RULE = 'ed-visit:v1';
    const CASE_KEY_PREFIX = 'case:v1';
    const KEY_CHECK_INPUT = 'encounter-key-check';
    const KEY_FILE_FORMAT = 'encounter-key';
    const LOCAL_MAP_FORMAT = 'encounter-local-map';
    const CARE_LEVELS = ['ward', 'monitoring', 'icu', 'or', 'unknown'];
    const CARE_LEVEL_DISPOSITIONS = ['admitted', 'transferred_external'];
    const VITAL_FIELDS = [
        'bp_systolic',
        'bp_diastolic',
        'heart_rate',
        'temperature',
        'respiratory_rate',
        'glucose',
        'spo2',
        'gcs',
    ];
    /** Name of a department of the reference catalog, as the import resolves it (#1048). */
    // Patterns of the server's content check (IdentifyingContentDetector, #1048): a value that matches
    // rejects the whole file there, so the local map must not produce one.
    const SERVER_NAME_LIKE =
        /\p{Lu}\p{Ll}+\s*,\s*\p{Lu}\p{Ll}+|\b(?:Dr|Prof|Herr|Frau|Mr|Mrs|Ms)\.?\s+\p{Lu}/u;
    const SERVER_DATE_LIKE =
        /\b\d{1,2}\.\d{1,2}\.\d{2,4}\b|\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/;

    function isDepartmentName(value) {
        return (
            typeof value === 'string' &&
            !SERVER_NAME_LIKE.test(value) &&
            !SERVER_DATE_LIKE.test(value) &&
            value.length >= 1 &&
            value.length <= 64 &&
            value.trim() === value &&
            ![...value].some(
                (char) =>
                    char === ';' ||
                    char === '"' ||
                    char.charCodeAt(0) < 32 ||
                    char.charCodeAt(0) === 127,
            )
        );
    }
    // Values from the hospital's own local map, not from the export: checked when the map is loaded
    // (isDepartmentName), not by the content check of the run.
    const LOCAL_MAP_FIELDS = ['admitting_department'];

    // ------------------------------------------------------------------ text helpers

    function normalizeText(value) {
        return String(value ?? '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function lookupKey(value) {
        return normalizeText(value).toLocaleLowerCase('de-DE');
    }

    const tables = new WeakMap();

    /** Case- and whitespace-insensitive lookup table from a plain object (cached per object). */
    function lookupTable(map) {
        if (map && tables.has(map)) {
            return tables.get(map);
        }
        const table = new Map();
        for (const [source, target] of Object.entries(map ?? {})) {
            table.set(lookupKey(source), target);
        }
        if (map) {
            tables.set(map, table);
        }
        return table;
    }

    function toHex(buffer) {
        return Array.from(new Uint8Array(buffer), (b) => b.toString(16).padStart(2, '0')).join('');
    }

    function fromHex(hex) {
        if (typeof hex !== 'string' || !/^([0-9a-f]{2})+$/i.test(hex)) {
            return null;
        }
        const bytes = new Uint8Array(hex.length / 2);
        for (let i = 0; i < bytes.length; i++) {
            bytes[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
        }
        return bytes;
    }

    function pad(number, length = 2) {
        return String(number).padStart(length, '0');
    }

    // ------------------------------------------------------------------ reading files

    /** UTF-8 if the bytes are valid UTF-8, otherwise Windows-1252 (ORBIS default). */
    function decodeBytes(bytes) {
        try {
            return {
                text: new TextDecoder('utf-8', { fatal: true }).decode(bytes),
                encoding: 'utf-8',
            };
        } catch {
            return {
                text: new TextDecoder('windows-1252').decode(bytes),
                encoding: 'windows-1252',
            };
        }
    }

    /** RFC 4180 style CSV with a configurable delimiter; quoted cells may contain line breaks. */
    function parseCsv(text, delimiter = ';') {
        const rows = [];
        let row = [];
        let cell = '';
        let quoted = false;
        let i = 0;
        const input = text.charCodeAt(0) === 0xfeff ? text.slice(1) : text;

        while (i < input.length) {
            const char = input[i];
            if (quoted) {
                if (char === '"') {
                    if (input[i + 1] === '"') {
                        cell += '"';
                        i += 2;
                        continue;
                    }
                    quoted = false;
                } else {
                    cell += char;
                }
                i++;
                continue;
            }
            if (char === '"' && cell === '') {
                quoted = true;
            } else if (char === delimiter) {
                row.push(cell);
                cell = '';
            } else if (char === '\r' || char === '\n') {
                row.push(cell);
                rows.push(row);
                row = [];
                cell = '';
                if (char === '\r' && input[i + 1] === '\n') {
                    i++;
                }
            } else {
                cell += char;
            }
            i++;
        }
        if (cell !== '' || row.length > 0) {
            row.push(cell);
            rows.push(row);
        }

        return rows.filter((cells) => cells.some((value) => value.trim() !== ''));
    }

    /** Header and rows of a decoded source file. */
    function readTable(text, delimiter = ';') {
        const [header = [], ...rows] = parseCsv(text, delimiter);
        return { header: header.map((name) => normalizeText(name)), rows };
    }

    function detectProfile(header, profiles) {
        const columns = new Set(header);
        return (
            profiles.find((profile) =>
                profile.detect.requiredColumns.every((column) => columns.has(column)),
            ) ?? null
        );
    }

    // ------------------------------------------------------------------ timestamps

    const SOURCE_TIMESTAMP =
        /^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/;

    /** `dd.mm.yyyy  HH:MM:SS` (ORBIS writes two spaces); seconds and time are optional. */
    function parseSourceTimestamp(value, { dateOnly = false } = {}) {
        const match = SOURCE_TIMESTAMP.exec(normalizeText(value));
        if (match === null || (!dateOnly && match[4] === undefined)) {
            return null;
        }
        const [day, month, year] = [Number(match[1]), Number(match[2]), Number(match[3])];
        const [hour, minute, second] = [
            Number(match[4] ?? 0),
            Number(match[5] ?? 0),
            Number(match[6] ?? 0),
        ];
        const check = new Date(Date.UTC(year, month - 1, day));
        if (
            check.getUTCFullYear() !== year ||
            check.getUTCMonth() !== month - 1 ||
            check.getUTCDate() !== day ||
            hour > 23 ||
            minute > 59 ||
            second > 59
        ) {
            return null;
        }
        return { year, month, day, hour, minute, second };
    }

    function formatDate(ts) {
        return `${pad(ts.year, 4)}-${pad(ts.month)}-${pad(ts.day)}`;
    }

    /** Exchange format: `YYYY-MM-DDTHH:MM` (seconds cut, not rounded). */
    function formatMinute(ts) {
        return `${formatDate(ts)}T${pad(ts.hour)}:${pad(ts.minute)}`;
    }

    /** Fingerprint input: `YYYY-MM-DDTHH:MM:SS`, `:00` if the source has no seconds. */
    function formatSecond(ts) {
        return `${formatMinute(ts)}:${pad(ts.second)}`;
    }

    function ageAt(birth, reference) {
        let age = reference.year - birth.year;
        if (
            reference.month < birth.month ||
            (reference.month === birth.month && reference.day < birth.day)
        ) {
            age--;
        }
        return age;
    }

    function clampAge(age) {
        return Math.min(99, Math.max(1, age));
    }

    // ------------------------------------------------------------------ numbers

    /**
     * Round a non-negative decimal string half up without binary floating point:
     * `99.5` → `100`, `99.49` → `99`; with one place `37.25` → `37.3`.
     */
    function roundHalfUp(decimal, places = 0) {
        const [integerPart, fraction = ''] = decimal.split('.');
        const digits = fraction.padEnd(places + 1, '0');
        let scaled = Number(integerPart + digits.slice(0, places));
        if (Number(digits[places]) >= 5) {
            scaled += 1;
        }
        if (places === 0) {
            return String(scaled);
        }
        const text = String(scaled).padStart(places + 1, '0');
        return `${text.slice(0, -places)}.${text.slice(-places)}`;
    }

    /** Number with an optional fixed unit (`99 Schl/min`, `37,5 °C`); null if not parseable. */
    function parseMeasurement(value, unit) {
        let text = normalizeText(value);
        if (unit && text.toLowerCase().endsWith(unit.toLowerCase())) {
            text = text.slice(0, -unit.length).trim();
        }
        text = text.replace(',', '.');
        return /^\d+(\.\d+)?$/.test(text) ? text : null;
    }

    function inRange(number, field) {
        return (
            (field.min === undefined || number >= field.min) &&
            (field.max === undefined || number <= field.max)
        );
    }

    // ------------------------------------------------------------------ catalogs

    /** Catalog code from a source label: `Abdominelle Schmerzen bei Erwachsenen` → `abdominelle_schmerzen_bei_erwachsenen`. */
    function catalogCode(value) {
        return normalizeText(value)
            .toLowerCase()
            .replace(/ä/g, 'ae')
            .replace(/ö/g, 'oe')
            .replace(/ü/g, 'ue')
            .replace(/ß/g, 'ss')
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .replace(/[^a-z0-9]+/g, '_')
            .slice(0, 64)
            .replace(/^_+|_+$/g, '');
    }

    function mapCode(rule, value) {
        const key = lookupKey(value);
        const table = lookupTable(rule.map);
        if (table.has(key)) {
            return table.get(key);
        }
        for (const [prefix, code] of Object.entries(rule.prefixes ?? {})) {
            if (key.startsWith(lookupKey(prefix))) {
                return code;
            }
        }
        return undefined;
    }

    function isEmptyMarker(rule, value) {
        const key = lookupKey(value);
        return key === '' || (rule.empty ?? []).some((marker) => lookupKey(marker) === key);
    }

    // ------------------------------------------------------------------ key file

    function cryptoApi() {
        if (!root.crypto?.subtle) {
            throw new Error('WebCrypto is not available in this browser.');
        }
        return root.crypto;
    }

    async function importHmacKey(rawKey) {
        return cryptoApi().subtle.importKey(
            'raw',
            rawKey,
            { name: 'HMAC', hash: 'SHA-256' },
            false,
            ['sign'],
        );
    }

    async function hmacHex(cryptoKey, message) {
        const signature = await cryptoApi().subtle.sign(
            'HMAC',
            cryptoKey,
            new TextEncoder().encode(message),
        );
        return toHex(signature);
    }

    /** New key file content: random 256-bit key, label, creation date and check value (ADR 027). */
    async function generateKeyFile(label, createdDate) {
        const raw = cryptoApi().getRandomValues(new Uint8Array(32));
        const check = await hmacHex(await importHmacKey(raw), KEY_CHECK_INPUT);
        return {
            format: KEY_FILE_FORMAT,
            version: 1,
            label: normalizeText(label),
            created: createdDate,
            key: toHex(raw),
            check,
        };
    }

    /** Validated key from key file content (object or JSON text); throws on any mismatch. */
    async function loadKey(content) {
        const data = typeof content === 'string' ? JSON.parse(content) : content;
        if (data?.format !== KEY_FILE_FORMAT || data.version !== 1) {
            throw new Error('not-a-key-file');
        }
        const raw = fromHex(data.key);
        if (raw === null || raw.length !== 32) {
            throw new Error('invalid-key');
        }
        const cryptoKey = await importHmacKey(raw);
        const check = await hmacHex(cryptoKey, KEY_CHECK_INPUT);
        if (check !== String(data.check).toLowerCase()) {
            throw new Error('check-mismatch');
        }
        return {
            cryptoKey,
            check,
            label: normalizeText(data.label),
            created: String(data.created ?? ''),
            file: { ...data, check },
        };
    }

    // ------------------------------------------------------------------ fingerprint

    function caseNumberOf(value) {
        return String(value ?? '').replace(/\D/g, '');
    }

    function fingerprintInput(caseNumber, anchorSource, anchor) {
        return `${FINGERPRINT_RULE}|${caseNumber}|${anchorSource}|${formatSecond(anchor)}`;
    }

    async function visitKeys(key, caseNumber, anchorSource, anchor) {
        return {
            visitFingerprint: await hmacHex(
                key.cryptoKey,
                fingerprintInput(caseNumber, anchorSource, anchor),
            ),
            caseKey: await hmacHex(key.cryptoKey, `${CASE_KEY_PREFIX}|${caseNumber}`),
        };
    }

    // ------------------------------------------------------------------ local map

    /** Validated private local map of a hospital (format: data-preparation-tool.md). */
    function loadLocalMap(content, profile) {
        const data = typeof content === 'string' ? JSON.parse(content) : content;
        if (data?.format !== LOCAL_MAP_FORMAT || data.version !== 1) {
            throw new Error('not-a-local-map');
        }
        if (data.profile !== undefined && data.profile !== profile.id) {
            throw new Error('profile-mismatch');
        }
        const errors = [];
        for (const [source, name] of Object.entries(data.departments ?? {})) {
            if (!isDepartmentName(name)) {
                errors.push(`departments.${source}`);
            }
        }
        for (const [source, level] of Object.entries(data.stations ?? {})) {
            if (!CARE_LEVELS.includes(level)) {
                errors.push(`stations.${source}`);
            }
        }
        for (const [source, target] of Object.entries(data.dispositions ?? {})) {
            if (!isValidDispositionTarget(target)) {
                errors.push(`dispositions.${source}`);
            }
        }
        if (errors.length > 0) {
            const error = new Error('invalid-local-map');
            error.entries = errors;
            throw error;
        }
        return {
            label: normalizeText(data.hospital ?? ''),
            data: localMapData(data, profile),
            departments: lookupTable(data.departments),
            stations: lookupTable(data.stations),
            dispositions: lookupTable(data.dispositions),
            counts: {
                departments: Object.keys(data.departments ?? {}).length,
                stations: Object.keys(data.stations ?? {}).length,
                dispositions: Object.keys(data.dispositions ?? {}).length,
            },
        };
    }

    /** Plain copy of a map in the file format, as the editor changes and saves it (#1130). */
    function localMapData(data, profile) {
        const copy = (section) => ({ ...(data?.[section] ?? {}) });
        return {
            format: LOCAL_MAP_FORMAT,
            version: 1,
            profile: data?.profile ?? profile.id,
            hospital: typeof data?.hospital === 'string' ? data.hospital : '',
            departments: copy('departments'),
            stations: copy('stations'),
            dispositions: Object.fromEntries(
                Object.entries(data?.dispositions ?? {}).map(([source, target]) => [
                    source,
                    { ...target },
                ]),
            ),
        };
    }

    /** Empty map for the profile, the start of a map made in the editor. */
    function newLocalMapData(profile, hospital = '') {
        return localMapData({ hospital }, profile);
    }

    /**
     * Report fields (`report.unmapped`) whose values the local map assigns, with the map section:
     * the `department` rule, the `disposition` rule and the care level from its station column.
     */
    function localMapSections(profile) {
        const sections = {};
        for (const [name, rule] of Object.entries(profile.fields)) {
            if (rule.type === 'department') {
                sections[name] = 'departments';
            } else if (rule.type === 'disposition') {
                sections[name] = 'dispositions';
                if (rule.stationColumn) {
                    sections.care_level = 'stations';
                }
            }
        }
        return sections;
    }

    /**
     * Copy of the map data with one entry set (or removed with `target === null`). The source value is
     * stored as the report shows it; an existing entry with the same lookup key is replaced. The result
     * is not validated here: load it with loadLocalMap, as a map file.
     */
    function setLocalMapEntry(data, section, source, target) {
        if (!['departments', 'stations', 'dispositions'].includes(section)) {
            throw new Error(`Unknown local map section "${section}".`);
        }
        const key = normalizeText(source);
        const entries = Object.entries(data[section] ?? {}).filter(
            ([existing]) => lookupKey(existing) !== lookupKey(key),
        );
        if (target !== null && target !== undefined) {
            entries.push([key, typeof target === 'object' ? { ...target } : target]);
        }
        return { ...data, [section]: Object.fromEntries(entries) };
    }

    /** The map file as saved by the tool: JSON, four spaces, final newline. */
    function serializeLocalMap(data) {
        return `${JSON.stringify(data, null, 4)}\n`;
    }

    const DISPOSITIONS = [
        'home',
        'to_physician',
        'kv_practice',
        'against_advice',
        'left_before_completion',
        'admitted',
        'transferred_external',
        'died',
        'other',
    ];

    function isValidDispositionTarget(target) {
        return (
            DISPOSITIONS.includes(target?.disposition) &&
            (target.careLevel === undefined ||
                (CARE_LEVELS.includes(target.careLevel) &&
                    CARE_LEVEL_DISPOSITIONS.includes(target.disposition)))
        );
    }

    function emptyLocalMap() {
        return {
            label: '',
            departments: new Map(),
            stations: new Map(),
            dispositions: new Map(),
            counts: { departments: 0, stations: 0, dispositions: 0 },
        };
    }

    // ------------------------------------------------------------------ column preview

    /** Which source columns are kept, transformed or dropped, and why (allow-list). */
    function columnPlan(header, profile) {
        const used = new Map();
        const use = (column, target, status) => {
            if (!column) {
                return;
            }
            const entry = used.get(column) ?? { status, targets: [] };
            if (status === 'transformed') {
                entry.status = 'transformed';
            }
            entry.targets.push(target);
            used.set(column, entry);
        };

        use(profile.keys.caseNumber, 'visit_fingerprint', 'transformed');
        use(profile.keys.caseNumber, 'case_key', 'transformed');
        for (const anchor of profile.keys.anchors) {
            use(anchor.column, 'visit_fingerprint', 'transformed');
        }
        use(profile.age.column, 'age', 'transformed');
        for (const [name, rule] of Object.entries(profile.fields)) {
            const targets = rule.targets ?? [name];
            const status = ['timestamp', 'integer'].includes(rule.type) ? 'kept' : 'transformed';
            for (const column of rule.columns ?? [rule.column]) {
                targets.forEach((target) => use(column, target, status));
            }
            if (rule.type === 'disposition') {
                use(rule.stationColumn, 'care_level', 'transformed');
            }
        }

        const dropReasons = new Map();
        for (const [reason, columns] of Object.entries(profile.dropped)) {
            if (reason === 'derivedPatterns') {
                continue;
            }
            columns.forEach((column) => dropReasons.set(column, reason));
        }
        const patterns = profile.dropped.derivedPatterns ?? [];
        const internalUse = new Map([[profile.age.birthDateColumn, ['age']]]);

        return header
            .map((column, index) => ({ column, index }))
            .filter(({ column }) => column !== '')
            .map(({ column, index }) => {
                const reason =
                    dropReasons.get(column) ??
                    (patterns.some((pattern) => column.includes(pattern)) ? 'derived' : null);
                if (reason !== null) {
                    const usedFor = used.get(column)?.targets ?? internalUse.get(column) ?? [];
                    return {
                        column,
                        index,
                        status: 'dropped',
                        reason,
                        usedFor: [...new Set(usedFor)],
                    };
                }
                if (used.has(column)) {
                    const entry = used.get(column);
                    return {
                        column,
                        index,
                        status: entry.status,
                        targets: [...new Set(entry.targets)],
                    };
                }
                return { column, index, status: 'dropped', reason: 'notInProfile', usedFor: [] };
            });
    }

    // ------------------------------------------------------------------ content checks

    const DATE_LIKE = /\b\d{1,2}\.\d{1,2}\.(\d{4}|\d{2})\b|\b\d{4}-\d{2}-\d{2}\b/;
    const NAME_LIKE = [
        /^[A-ZÄÖÜ][a-zäöüß]+(?:[- ][A-ZÄÖÜ][a-zäöüß]+)*,\s*[A-ZÄÖÜ][a-zäöüß]+/,
        /\b(?:Herr|Frau|Hr\.|Fr\.)\s+[A-ZÄÖÜ][a-zäöüß]+/,
    ];
    const LONG_TEXT_CHARACTERS = 100;
    const LONG_TEXT_WORDS = 12;

    /** Findings for one value that is about to reach (or feed) a non-date output field. */
    function contentFindings(value, { dateField = false } = {}) {
        const text = normalizeText(value);
        if (text === '') {
            return [];
        }
        const findings = [];
        if (!dateField && DATE_LIKE.test(text)) {
            findings.push('date');
        }
        if (NAME_LIKE.some((pattern) => pattern.test(text))) {
            findings.push('name');
        }
        if (text.length > LONG_TEXT_CHARACTERS || text.split(' ').length >= LONG_TEXT_WORDS) {
            findings.push('freeText');
        }
        return findings;
    }

    function recordFinding(findings, field, column, kind, line) {
        const id = `${field}|${column}|${kind}`;
        const entry = findings.get(id) ?? { field, column, kind, count: 0, firstLine: line };
        entry.count++;
        findings.set(id, entry);
    }

    // ------------------------------------------------------------------ transformation

    function newReport(rowsRead) {
        return {
            rowsRead,
            rowsWritten: 0,
            skipped: { noCaseNumber: 0, noAnchor: 0, noAge: 0, noSex: 0, duplicate: 0 },
            anchorSources: { assessment: 0, admission: 0 },
            ageFromBirthDate: 0,
            ageClamped: 0,
            vitalsEmptied: {},
            invalidValues: {},
            unmapped: {},
            unlistedCatalogValues: {},
            careLevelUnknown: 0,
            firstAnchor: null,
            lastAnchor: null,
        };
    }

    function count(bucket, field, sub) {
        bucket[field] ??= {};
        bucket[field][sub] = (bucket[field][sub] ?? 0) + 1;
    }

    /**
     * Turn the rows of a source table into canonical `ed_visit` rows.
     *
     * @returns {Promise<{header: string[], rows: string[][], report: object, content: object}>}
     */
    async function transform(
        table,
        { profile, schema, key, localMap = emptyLocalMap(), catalogs = {} },
    ) {
        const fieldsByName = new Map(schema.fields.map((field) => [field.name, field]));
        const outputHeader = schema.fields.map((field) => field.name);
        // ORBIS repeats some headers (`Zweiteinschätzung`); the first occurrence wins.
        const columnIndex = new Map();
        table.header.forEach((column, index) => {
            if (!columnIndex.has(column)) {
                columnIndex.set(column, index);
            }
        });
        const catalogTables = catalogLookups(catalogs);
        const cell = (row, column) =>
            columnIndex.has(column) ? normalizeText(row[columnIndex.get(column)]) : '';
        const report = newReport(table.rows.length);
        const findings = new Map();
        const seen = new Set();
        const rows = [];

        for (let r = 0; r < table.rows.length; r++) {
            const row = table.rows[r];
            const line = r + 2;
            const out = {};
            const check = (field, column, value, dateField = false) => {
                for (const kind of contentFindings(value, { dateField })) {
                    recordFinding(findings, field, column, kind, line);
                }
            };

            // keys
            const caseNumber = caseNumberOf(cell(row, profile.keys.caseNumber));
            if (caseNumber === '') {
                report.skipped.noCaseNumber++;
                continue;
            }
            let anchor = null;
            let anchorSource = null;
            for (const candidate of profile.keys.anchors) {
                anchor = parseSourceTimestamp(cell(row, candidate.column));
                if (anchor !== null) {
                    anchorSource = candidate.source;
                    break;
                }
            }
            if (anchor === null) {
                report.skipped.noAnchor++;
                continue;
            }

            // demographics (required)
            const ageText = /^(\d{1,3})\b/.exec(cell(row, profile.age.column));
            let age = ageText === null ? null : Number(ageText[1]);
            if (age === null && profile.age.birthDateColumn) {
                const birth = parseSourceTimestamp(cell(row, profile.age.birthDateColumn), {
                    dateOnly: true,
                });
                if (birth !== null) {
                    age = ageAt(birth, anchor);
                    report.ageFromBirthDate++;
                }
            }
            if (age === null) {
                report.skipped.noAge++;
                continue;
            }
            if (clampAge(age) !== age) {
                report.ageClamped++;
            }
            out.age = String(clampAge(age));

            const sexRule = profile.fields.sex;
            const sex = mapCode(sexRule, cell(row, sexRule.column));
            if (sex === undefined) {
                const raw = cell(row, sexRule.column);
                if (raw !== '') {
                    count(report.unmapped, 'sex', raw);
                }
                report.skipped.noSex++;
                continue;
            }
            out.sex = sex;

            const keys = await visitKeys(key, caseNumber, anchorSource, anchor);
            if (seen.has(keys.visitFingerprint)) {
                report.skipped.duplicate++;
                continue;
            }
            seen.add(keys.visitFingerprint);
            out.visit_fingerprint = keys.visitFingerprint;
            out.case_key = keys.caseKey;
            report.anchorSources[anchorSource]++;
            const anchorMinute = formatMinute(anchor);
            if (report.firstAnchor === null || anchorMinute < report.firstAnchor) {
                report.firstAnchor = anchorMinute;
            }
            if (report.lastAnchor === null || anchorMinute > report.lastAnchor) {
                report.lastAnchor = anchorMinute;
            }

            for (const [name, rule] of Object.entries(profile.fields)) {
                if (name === 'sex') {
                    continue;
                }
                applyRule(name, rule, {
                    row,
                    cell,
                    out,
                    report,
                    localMap,
                    fieldsByName,
                    check,
                    catalogTables,
                });
            }

            if (VITAL_FIELDS.some((field) => (out[field] ?? '') !== '')) {
                out.vitals_reference = profile.vitalsReference;
            }

            for (const field of outputHeader) {
                const type = fieldsByName.get(field).type;
                if (type !== 'timestamp' && type !== 'hmac' && !LOCAL_MAP_FIELDS.includes(field)) {
                    check(field, null, out[field] ?? '');
                }
            }
            rows.push(outputHeader.map((field) => out[field] ?? ''));
        }

        report.rowsWritten = rows.length;
        const contentList = [...findings.values()];
        return {
            header: outputHeader,
            rows,
            report,
            content: { passed: contentList.length === 0, findings: contentList },
        };
    }

    function applyRule(name, rule, context) {
        const { cell, row, out, report, localMap, fieldsByName, check, catalogTables } = context;
        const raw = rule.column ? cell(row, rule.column) : '';

        switch (rule.type) {
            case 'code': {
                if (isEmptyMarker(rule, raw)) {
                    return;
                }
                const code = mapCode(rule, raw);
                if (code === undefined) {
                    count(report.unmapped, name, raw);
                    return;
                }
                out[name] = code;
                return;
            }
            case 'timestamp': {
                if (raw === '') {
                    return;
                }
                const ts = parseSourceTimestamp(raw);
                if (ts === null) {
                    count(report.invalidValues, name, 'timestamp');
                    return;
                }
                out[name] = formatMinute(ts);
                return;
            }
            case 'catalog': {
                if (isEmptyMarker(rule, raw)) {
                    return;
                }
                check(name, rule.column, raw);
                const listed = catalogTables.get(`${rule.catalog}/${rule.list}`);
                if (listed !== undefined && !listed.has(lookupKey(raw))) {
                    if (rule.unlisted === 'empty') {
                        count(report.unmapped, name, raw);
                        return;
                    }
                    count(report.unlistedCatalogValues, name, raw);
                }
                out[name] = catalogCode(raw);
                return;
            }
            case 'integer': {
                if (raw === '') {
                    return;
                }
                const match = /^(\d+)\b/.exec(raw);
                const number = match === null ? null : Number(match[1]);
                if (number === null || !inRange(number, fieldsByName.get(name))) {
                    count(
                        report.invalidValues,
                        name,
                        number === null ? 'unparseable' : 'outOfRange',
                    );
                    return;
                }
                out[name] = String(number);
                return;
            }
            case 'vital': {
                const value = vitalValue(raw, rule, fieldsByName.get(name));
                if (value.reason) {
                    count(report.vitalsEmptied, name, value.reason);
                }
                if (value.text) {
                    out[name] = value.text;
                }
                return;
            }
            case 'bloodPressure': {
                if (raw === '') {
                    return;
                }
                let text = raw;
                if (rule.unit && text.toLowerCase().endsWith(rule.unit.toLowerCase())) {
                    text = text.slice(0, -rule.unit.length).trim();
                }
                const match = /^(\d{1,3})\s*\/\s*(\d{1,3}|\?)?$/.exec(text);
                const [systolicField, diastolicField] = rule.targets;
                if (match === null) {
                    count(report.vitalsEmptied, systolicField, 'unparseable');
                    count(report.vitalsEmptied, diastolicField, 'unparseable');
                    return;
                }
                const parts = [
                    [systolicField, match[1]],
                    [diastolicField, match[2]],
                ];
                for (const [field, part] of parts) {
                    if (part === undefined || part === '?') {
                        continue;
                    }
                    if (!inRange(Number(part), fieldsByName.get(field))) {
                        count(report.vitalsEmptied, field, 'outOfRange');
                        continue;
                    }
                    out[field] = String(Number(part));
                }
                return;
            }
            case 'flag': {
                if (raw === '') {
                    return;
                }
                if ((rule.yes ?? []).some((yes) => lookupKey(yes) === lookupKey(raw))) {
                    out[name] = '1';
                } else {
                    count(report.unmapped, name, raw);
                }
                return;
            }
            case 'codeSet': {
                const codes = new Set();
                for (const column of rule.columns) {
                    for (const part of cell(row, column).split(',')) {
                        if (isEmptyMarker(rule, part)) {
                            continue;
                        }
                        const code = mapCode(rule, part);
                        if (code === undefined) {
                            count(report.unmapped, name, normalizeText(part));
                        } else {
                            codes.add(code);
                        }
                    }
                }
                if (codes.size > 0) {
                    out[name] = [...codes].sort().join(',');
                }
                return;
            }
            case 'disposition':
                applyDisposition(name, rule, raw, context);
                return;
            case 'department': {
                const source = rule.columns
                    .map((column) => cell(row, column))
                    .find((value) => value !== '');
                if (source === undefined) {
                    return;
                }
                const code = localMap.departments.get(lookupKey(source));
                if (code === undefined) {
                    count(report.unmapped, name, source);
                    return;
                }
                out[name] = code;
                return;
            }
            default:
                throw new Error(`Unknown rule type "${rule.type}" in profile.`);
        }
    }

    /** `catalogId/list` → set of lookup keys of the listed labels. */
    function catalogLookups(catalogs) {
        const tables = new Map();
        for (const [id, catalog] of Object.entries(catalogs)) {
            for (const [list, labels] of Object.entries(catalog)) {
                if (Array.isArray(labels)) {
                    tables.set(`${id}/${list}`, new Set(labels.map(lookupKey)));
                }
            }
        }
        return tables;
    }

    function vitalValue(raw, rule, field) {
        if (raw === '') {
            return {};
        }
        const decimal = parseMeasurement(raw, rule.unit);
        if (decimal === null) {
            return { reason: 'unparseable' };
        }
        let text;
        if (rule.decimals) {
            text = roundHalfUp(decimal, rule.decimals);
        } else if (rule.round) {
            text = roundHalfUp(decimal, 0);
        } else if (/^\d+$/.test(decimal)) {
            text = String(Number(decimal));
        } else {
            return { reason: 'unparseable' };
        }
        if (!inRange(Number(text), field)) {
            return { reason: 'outOfRange' };
        }
        return { text };
    }

    function applyDisposition(name, rule, raw, { cell, row, out, report, localMap }) {
        if (raw === '') {
            return;
        }
        const target =
            localMap.dispositions.get(lookupKey(raw)) ?? lookupTable(rule.map).get(lookupKey(raw));
        if (target === undefined) {
            count(report.unmapped, name, raw);
            return;
        }
        out[name] = target.disposition;
        if (!CARE_LEVEL_DISPOSITIONS.includes(target.disposition)) {
            return;
        }
        let careLevel = target.careLevel ?? null;
        if (target.disposition === 'admitted' && rule.stationColumn) {
            const station = cell(row, rule.stationColumn);
            const mapped = station === '' ? undefined : localMap.stations.get(lookupKey(station));
            if (mapped !== undefined) {
                careLevel = mapped;
            } else if (station !== '') {
                count(report.unmapped, 'care_level', station);
            }
        }
        if (careLevel === null || careLevel === 'unknown') {
            report.careLevelUnknown++;
        }
        out.care_level = careLevel ?? 'unknown';
    }

    // ------------------------------------------------------------------ output

    function manifestLine({ profile, schema, toolVersion, keyCheck, rows }) {
        return [
            '#encounter-exchange',
            `schema=${schema.schema}`,
            `schema_version=${schema.version}`,
            `profile=${profile.id}`,
            `profile_version=${profile.version}`,
            `tool=${toolVersion}`,
            `key_check=${keyCheck}`,
            `fingerprint=${FINGERPRINT_RULE}`,
            `rows=${rows}`,
        ].join(';');
    }

    function csvCell(value) {
        return /[;"\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
    }

    /** Canonical exchange file: manifest, header, rows; UTF-8, `;`, LF. */
    function buildExchangeFile(result, { profile, schema, toolVersion, keyCheck }) {
        const lines = [
            manifestLine({ profile, schema, toolVersion, keyCheck, rows: result.rows.length }),
            result.header.join(';'),
            ...result.rows.map((row) => row.map(csvCell).join(';')),
        ];
        return `${lines.join('\n')}\n`;
    }

    function exchangeFileName(result, profile) {
        const first = result.report.firstAnchor?.slice(0, 10) ?? 'empty';
        const last = result.report.lastAnchor?.slice(0, 10) ?? 'empty';
        return `ed_visit_${profile.id}_${first}_${last}.csv`;
    }

    // ------------------------------------------------------------------ versions

    function compareVersions(a, b) {
        const left = String(a).split('.').map(Number);
        const right = String(b).split('.').map(Number);
        for (let i = 0; i < Math.max(left.length, right.length); i++) {
            const diff = (left[i] ?? 0) - (right[i] ?? 0);
            if (diff !== 0) {
                return diff < 0 ? -1 : 1;
            }
        }
        return 0;
    }

    /** Update state from the `latest.json` payload: `current`, `update` or `blocked`. */
    function updateState(ownVersion, latest) {
        if (compareVersions(ownVersion, latest.minVersion) < 0) {
            return 'blocked';
        }
        return compareVersions(ownVersion, latest.version) < 0 ? 'update' : 'current';
    }

    root.EncounterDataPrep = Object.freeze({
        FINGERPRINT_RULE,
        KEY_CHECK_INPUT,
        decodeBytes,
        parseCsv,
        readTable,
        detectProfile,
        parseSourceTimestamp,
        formatMinute,
        formatSecond,
        roundHalfUp,
        catalogCode,
        contentFindings,
        caseNumberOf,
        fingerprintInput,
        hmacHex,
        importHmacKey,
        generateKeyFile,
        loadKey,
        CARE_LEVELS,
        CARE_LEVEL_DISPOSITIONS,
        DISPOSITIONS,
        isDepartmentName,
        loadLocalMap,
        emptyLocalMap,
        newLocalMapData,
        localMapSections,
        setLocalMapEntry,
        serializeLocalMap,
        columnPlan,
        transform,
        buildExchangeFile,
        exchangeFileName,
        compareVersions,
        updateState,
    });
})(globalThis);
