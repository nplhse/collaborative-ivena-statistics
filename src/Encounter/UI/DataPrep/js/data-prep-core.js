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

    const KEY_CHECK_INPUT = 'encounter-key-check';
    const KEY_FILE_FORMAT = 'encounter-key';
    const LOCAL_MAP_FORMAT = 'encounter-local-map';
    /** A list of names the import resolves, bundled into the tool when it is downloaded (#1638). */
    const NAME_LIST_FORMAT = 'encounter-name-list';
    // ------------------------------------------------------------------ schema modules

    /**
     * Schema modules (#1301): what a schema adds to the generic core, registered by its own script after this one
     * (for `ed_visit`: schemas/ed-visit.js). A schema without module works with the profile, the schema file and the
     * generic rule types alone.
     */
    const schemaModules = new Map();
    const GENERIC_MODULE = Object.freeze({
        skipReasons: [],
        report: () => ({}),
        beforeKeys: () => null,
        handledFields: [],
        ruleTypes: {},
        afterRow: () => {},
        localMapFields: [],
        columnPlan: () => {},
        internalColumns: () => [],
        localMap: { sections: [], reportSections: () => ({}) },
    });

    function registerSchema(id, module) {
        if (schemaModules.has(id)) {
            throw new Error(`Schema module "${id}" is already registered.`);
        }
        schemaModules.set(id, Object.freeze({ ...GENERIC_MODULE, ...module, id }));
    }

    function schemaModule(id) {
        return schemaModules.get(id) ?? Object.freeze({ ...GENERIC_MODULE, id });
    }

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

    /** Every profile whose required columns the header has; the order of the profiles never matters. */
    function matchingProfiles(header, profiles) {
        const columns = new Set(header);
        return profiles.filter((profile) =>
            profile.detect.requiredColumns.every((column) => columns.has(column)),
        );
    }

    /** The one matching profile, or null when none or several match (#1301: never the first of several). */
    function detectProfile(header, profiles) {
        const matches = matchingProfiles(header, profiles);
        return matches.length === 1 ? matches[0] : null;
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

    /**
     * Input of a keyed value: `rule|part|part…` after the key rules of the schema version (`schema.keys`, rendered
     * from the dimension, #1301). Parts: `case_number` (digits), `anchor_source`, `anchor` (with seconds).
     */
    function keyInput(rule, parts, { caseNumber, anchorSource, anchor }) {
        const value = (part) => {
            switch (part) {
                case 'case_number':
                    return caseNumber;
                case 'anchor_source':
                    return anchorSource;
                case 'anchor':
                    return formatSecond(anchor);
                default:
                    throw new Error(`Unknown key part "${part}".`);
            }
        };
        return [rule, ...parts.map(value)].join('|');
    }

    /** Fingerprint and case key of one record, by the field names of the key rules. */
    async function recordKeys(key, keys, values) {
        return {
            fingerprint: await hmacHex(
                key.cryptoKey,
                keyInput(keys.fingerprint.rule, keys.fingerprint.parts, values),
            ),
            caseKey: await hmacHex(
                key.cryptoKey,
                keyInput(keys.caseKey.prefix, keys.caseKey.parts, values),
            ),
        };
    }

    // ------------------------------------------------------------------ local map

    /**
     * Validated private local map of a hospital (format: data-preparation-tool.md); sections from the schema.
     * A section with a `catalog` (a name list in `catalogs`) also lists the entries whose target is not one of its
     * names in `warnings`: the map stays usable, but the import would reject those rows (#1638).
     */
    function loadLocalMap(content, profile, catalogs = {}) {
        const data = typeof content === 'string' ? JSON.parse(content) : content;
        if (data?.format !== LOCAL_MAP_FORMAT || data.version !== 1) {
            throw new Error('not-a-local-map');
        }
        if (data.profile !== undefined && data.profile !== profile.id) {
            throw new Error('profile-mismatch');
        }
        const { sections } = schemaModule(profile.schema).localMap;
        const errors = [];
        for (const section of sections) {
            for (const [source, target] of Object.entries(data[section.name] ?? {})) {
                if (!section.valid(target)) {
                    errors.push(`${section.name}.${source}`);
                }
            }
        }
        if (errors.length > 0) {
            const error = new Error('invalid-local-map');
            error.entries = errors;
            throw error;
        }
        const loaded = {
            label: normalizeText(data.hospital ?? ''),
            data: localMapData(data, profile),
        };
        for (const section of sections) {
            loaded[section.name] = lookupTable(data[section.name]);
        }
        loaded.counts = Object.fromEntries(
            sections.map((section) => [section.name, Object.keys(data[section.name] ?? {}).length]),
        );
        loaded.warnings = [];
        for (const section of sections) {
            const names = sectionNames(section, catalogs);
            if (names === null) {
                continue;
            }
            const known = new Set(names.map(lookupKey));
            for (const [source, target] of Object.entries(data[section.name] ?? {})) {
                if (!known.has(lookupKey(target))) {
                    loaded.warnings.push(`${section.name}.${source}`);
                }
            }
        }
        return loaded;
    }

    /** Names a local map section may assign, from its name-list catalog; null without such a catalog. */
    function sectionNames(section, catalogs) {
        const catalog = section.catalog === undefined ? undefined : catalogs[section.catalog];
        return catalog?.format === NAME_LIST_FORMAT ? catalog.names : null;
    }

    /** The names of a map section of the profile's schema (#1638), or null when the tool has no list for it. */
    function localMapNames(profile, sectionName, catalogs) {
        const section = schemaModule(profile.schema).localMap.sections.find(
            ({ name }) => name === sectionName,
        );
        return section === undefined ? null : sectionNames(section, catalogs);
    }

    /** Plain copy of a map in the file format, as the editor changes and saves it (#1130). */
    function localMapData(data, profile) {
        const map = {
            format: LOCAL_MAP_FORMAT,
            version: 1,
            profile: data?.profile ?? profile.id,
            hospital: typeof data?.hospital === 'string' ? data.hospital : '',
        };
        for (const { name } of schemaModule(profile.schema).localMap.sections) {
            map[name] = Object.fromEntries(
                Object.entries(data?.[name] ?? {}).map(([source, target]) => [
                    source,
                    typeof target === 'object' && target !== null ? { ...target } : target,
                ]),
            );
        }
        return map;
    }

    /** Empty map for the profile, the start of a map made in the editor. */
    function newLocalMapData(profile, hospital = '') {
        return localMapData({ hospital }, profile);
    }

    /** Report fields (`report.unmapped`) whose values the local map assigns, with the map section. */
    function localMapSections(profile) {
        return schemaModule(profile.schema).localMap.reportSections(profile);
    }

    /**
     * Copy of the map data with one entry set (or removed with `target === null`). The source value is
     * stored as the report shows it; an existing entry with the same lookup key is replaced. The result
     * is not validated here: load it with loadLocalMap, as a map file.
     */
    function setLocalMapEntry(data, section, source, target) {
        if (
            ['format', 'version', 'profile', 'hospital'].includes(section) ||
            !Object.hasOwn(data, section)
        ) {
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

    /** A map without entries; with a profile, with the sections of its schema. */
    function emptyLocalMap(profile = null) {
        const sections = profile === null ? [] : schemaModule(profile.schema).localMap.sections;
        const map = { label: '', warnings: [] };
        for (const { name } of sections) {
            map[name] = new Map();
        }
        map.counts = Object.fromEntries(sections.map(({ name }) => [name, 0]));
        return map;
    }

    // ------------------------------------------------------------------ column preview

    /** Which source columns are kept, transformed or dropped, and why (allow-list). */
    function columnPlan(header, profile, schema) {
        const module = schemaModule(profile.schema);
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

        const { fingerprint, caseKey } = schema.keys;
        use(profile.keys.caseNumber, fingerprint.field, 'transformed');
        use(profile.keys.caseNumber, caseKey.field, 'transformed');
        for (const anchor of profile.keys.anchors) {
            use(anchor.column, fingerprint.field, 'transformed');
        }
        module.columnPlan({ use, profile });
        for (const [name, rule] of Object.entries(profile.fields)) {
            const targets = rule.targets ?? [name];
            const status = ['timestamp', 'integer'].includes(rule.type) ? 'kept' : 'transformed';
            for (const column of rule.columns ?? [rule.column]) {
                targets.forEach((target) => use(column, target, status));
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
        const internalUse = new Map(module.internalColumns(profile));

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

    function newReport(rowsRead, profile, module) {
        const skipped = { noCaseNumber: 0, noAnchor: 0 };
        for (const reason of module.skipReasons) {
            skipped[reason] = 0;
        }
        skipped.duplicate = 0;
        return {
            rowsRead,
            rowsWritten: 0,
            skipped,
            anchorSources: Object.fromEntries(
                profile.keys.anchors.map(({ source }) => [source, 0]),
            ),
            ...module.report(),
            invalidValues: {},
            unmapped: {},
            unlistedCatalogValues: {},
            catalogAliases: {},
            firstAnchor: null,
            lastAnchor: null,
        };
    }

    function count(bucket, field, sub) {
        bucket[field] ??= {};
        bucket[field][sub] = (bucket[field][sub] ?? 0) + 1;
    }

    /**
     * Turn the rows of a source table into canonical rows of the schema (`schema.schema`).
     *
     * @returns {Promise<{header: string[], rows: string[][], report: object, content: object}>}
     */
    async function transform(
        table,
        { profile, schema, key, localMap = emptyLocalMap(), catalogs = {} },
    ) {
        const keyRules = schema.keys;
        if (!keyRules?.fingerprint || !keyRules?.caseKey) {
            throw new Error('The schema has no key rules.');
        }
        const module = schemaModule(schema.schema);
        // A map without a section of the schema (an empty map, a map of another profile) counts as empty there.
        for (const { name } of module.localMap.sections) {
            if (!(localMap[name] instanceof Map)) {
                localMap = { ...localMap, [name]: new Map() };
            }
        }
        const fieldsByName = new Map(schema.fields.map((field) => [field.name, field]));
        const outputHeader = exportFields(schema);
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
        const report = newReport(table.rows.length, profile, module);
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

            // Schema hooks may read source values such as the birth date here; only the export fields leave the row.
            const skip = module.beforeKeys({ row, cell, out, report, profile, anchor });
            if (skip !== null) {
                report.skipped[skip]++;
                continue;
            }

            const keys = await recordKeys(key, keyRules, { caseNumber, anchorSource, anchor });
            if (seen.has(keys.fingerprint)) {
                report.skipped.duplicate++;
                continue;
            }
            seen.add(keys.fingerprint);
            out[keyRules.fingerprint.field] = keys.fingerprint;
            out[keyRules.caseKey.field] = keys.caseKey;
            report.anchorSources[anchorSource]++;
            const anchorMinute = formatMinute(anchor);
            if (report.firstAnchor === null || anchorMinute < report.firstAnchor) {
                report.firstAnchor = anchorMinute;
            }
            if (report.lastAnchor === null || anchorMinute > report.lastAnchor) {
                report.lastAnchor = anchorMinute;
            }

            for (const [name, rule] of Object.entries(profile.fields)) {
                if (module.handledFields.includes(name)) {
                    continue;
                }
                applyRule(name, rule, module, {
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

            module.afterRow({ out, profile, report });

            for (const field of outputHeader) {
                const type = fieldsByName.get(field).type;
                if (
                    type !== 'timestamp' &&
                    type !== 'hmac' &&
                    !module.localMapFields.includes(field)
                ) {
                    check(field, null, out[field] ?? '');
                }
            }
            rows.push(exportRow(out, outputHeader));
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

    /**
     * Export boundary (#1301): the fields of the active schema version, in header order. Nothing else from a row
     * reaches the exchange file, whatever a profile or a schema hook read on the way (case number, birth date,
     * columns the profile does not know).
     */
    function exportFields(schema) {
        return schema.fields.map((field) => field.name);
    }

    function exportRow(out, fields) {
        return fields.map((field) => out[field] ?? '');
    }

    function applyRule(name, rule, module, context) {
        const { cell, row, out, report, fieldsByName, check, catalogTables } = context;
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
                // The code derived from the source label is written as it is, also for an alias (ADR 032): the
                // server resolves aliases when it reads, so the spelling the hospital sent stays visible.
                const code = catalogCode(raw);
                const listed = catalogTables.get(`${rule.catalog}/${rule.list}`);
                if (listed !== undefined && listed.aliases.has(code)) {
                    count(report.catalogAliases, name, `${raw} → ${listed.aliases.get(code)}`);
                } else if (listed !== undefined && !listed.codes.has(code)) {
                    if (rule.unlisted === 'empty') {
                        count(report.unmapped, name, raw);
                        return;
                    }
                    count(report.unlistedCatalogValues, name, raw);
                }
                out[name] = code;
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
            default:
                if (Object.hasOwn(module.ruleTypes, rule.type)) {
                    module.ruleTypes[rule.type](name, rule, raw, context);
                    return;
                }
                throw new Error(`Unknown rule type "${rule.type}" in profile.`);
        }
    }

    /**
     * `catalogId/list` → codes of the entries and aliases of the list (aliases: alias code → target code). A source
     * label is known when its code is one of them, the same rule as on the server (ADR 032).
     */
    function catalogLookups(catalogs) {
        const tables = new Map();
        for (const [id, catalog] of Object.entries(catalogs)) {
            if (catalog.format === NAME_LIST_FORMAT) {
                continue;
            }
            const lists = Object.keys(catalog).filter((list) => Array.isArray(catalog[list]));
            for (const list of lists) {
                const aliases = new Map(Object.entries(catalog.aliases?.[list] ?? {}));
                const codes = new Set([
                    ...(catalog[list] ?? []).map((entry) => entry.code),
                    ...aliases.keys(),
                ]);
                tables.set(`${id}/${list}`, { codes, aliases });
            }
        }
        return tables;
    }

    /**
     * Validate a catalog in the tool's format (for `ed_visit` the MTS catalog, #1157), as served by its public
     * endpoint and bundled as offline fallback: the schema module that knows the format checks it.
     */
    function parseCatalog(payload) {
        if (payload?.format === NAME_LIST_FORMAT) {
            return parseNameList(payload);
        }
        for (const module of schemaModules.values()) {
            if (module.catalogFormat !== undefined && payload?.format === module.catalogFormat) {
                return module.parseCatalog(payload);
            }
        }
        throw new Error('Not a catalog of a supported format.');
    }

    /** A name list as written by the server (`id`, `names`: distinct strings), see NAME_LIST_FORMAT. */
    function parseNameList(payload) {
        const { id, names } = payload;
        if (
            payload.version !== 1 ||
            typeof id !== 'string' ||
            id === '' ||
            !Array.isArray(names) ||
            names.some((name) => typeof name !== 'string' || name === '')
        ) {
            throw new Error('Not a valid name list.');
        }
        return Object.freeze({ format: NAME_LIST_FORMAT, id, names: Object.freeze([...names]) });
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
            `fingerprint=${schema.keys.fingerprint.rule}`,
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
        return `${profile.schema}_${profile.id}_${first}_${last}.csv`;
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

    // Shared helpers for schema modules.
    const helpers = Object.freeze({
        normalizeText,
        lookupKey,
        lookupTable,
        parseSourceTimestamp,
        ageAt,
        clampAge,
        parseMeasurement,
        roundHalfUp,
        inRange,
        mapCode,
        count,
    });

    root.EncounterDataPrep = Object.freeze({
        helpers,
        KEY_CHECK_INPUT,
        decodeBytes,
        parseCsv,
        readTable,
        detectProfile,
        matchingProfiles,
        parseSourceTimestamp,
        formatMinute,
        formatSecond,
        roundHalfUp,
        catalogCode,
        parseCatalog,
        contentFindings,
        caseNumberOf,
        keyInput,
        hmacHex,
        importHmacKey,
        generateKeyFile,
        loadKey,
        registerSchema,
        schemaModule,
        exportFields,
        loadLocalMap,
        localMapNames,
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
