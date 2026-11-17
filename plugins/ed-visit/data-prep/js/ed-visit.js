/*
 * Schema module `ed_visit` of the encounter data preparation tool (#1301): what the generic core
 * (data-prep-core.js) does not know about ED visits. Required age and sex, vital signs, blood pressure,
 * dispositions with care levels, departments and stations of the local map, the MTS catalog format and the
 * vitals reference. Loaded after the core, before the app; the server inlines it into the offline HTML file.
 *
 * Rules: docs/04-features/encounter/exchange-format.md and data-preparation-tool.md.
 */
(function (root) {
    'use strict';

    const prep = root.EncounterDataPrep;
    const {
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
    } = prep.helpers;

    const CATALOG_FORMAT = 'encounter-mts-catalog';
    const CATALOG_LISTS = ['diagrams', 'indicators'];
    const CATALOG_CODE = /^[a-z0-9_]{1,64}$/;
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

    /**
     * Validate an MTS catalog in the tool's format (`encounter-mts-catalog` version 1, #1157), as served by the
     * public catalog endpoint and bundled as offline fallback. Returns a normalised copy; throws on anything else.
     */
    function parseCatalog(payload) {
        if (
            payload === null ||
            typeof payload !== 'object' ||
            payload.format !== CATALOG_FORMAT ||
            payload.version !== 1 ||
            typeof payload.id !== 'string'
        ) {
            throw new Error('Not an MTS catalog of a supported version.');
        }
        const catalog = { format: CATALOG_FORMAT, version: 1, id: payload.id, aliases: {} };
        for (const list of CATALOG_LISTS) {
            const entries = payload[list];
            if (!Array.isArray(entries)) {
                throw new Error(`The MTS catalog has no list "${list}".`);
            }
            catalog[list] = entries.map((entry) => {
                if (
                    entry === null ||
                    typeof entry !== 'object' ||
                    !CATALOG_CODE.test(entry.code) ||
                    typeof entry.name !== 'string'
                ) {
                    throw new Error(`Invalid entry in the MTS catalog list "${list}".`);
                }
                return {
                    code: entry.code,
                    name: entry.name,
                    englishName: typeof entry.englishName === 'string' ? entry.englishName : null,
                };
            });
            const aliases = payload.aliases?.[list] ?? {};
            if (aliases === null || typeof aliases !== 'object') {
                throw new Error(`Invalid aliases in the MTS catalog list "${list}".`);
            }
            catalog.aliases[list] = {};
            for (const [code, target] of Object.entries(aliases)) {
                if (!CATALOG_CODE.test(code) || !CATALOG_CODE.test(target)) {
                    throw new Error(`Invalid alias in the MTS catalog list "${list}".`);
                }
                catalog.aliases[list][code] = target;
            }
        }
        return catalog;
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

    prep.registerSchema('ed_visit', {
        // Constants and checks the local map editor of the app uses.
        CARE_LEVELS,
        CARE_LEVEL_DISPOSITIONS,
        DISPOSITIONS,
        isDepartmentName,

        catalogFormat: CATALOG_FORMAT,
        parseCatalog,
        catalogLists: CATALOG_LISTS,

        skipReasons: ['noAge', 'noSex'],
        report() {
            return { ageFromBirthDate: 0, ageClamped: 0, vitalsEmptied: {}, careLevelUnknown: 0 };
        },

        /**
         * Required demographics before the keys: age (from the age column, else from the birth date at the anchor,
         * clamped to 1..99) and sex. Returns the skip reason of a row without them.
         */
        beforeKeys({ row, cell, out, report, profile, anchor }) {
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
                return 'noAge';
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
                return 'noSex';
            }
            out.sex = sex;
            return null;
        },
        handledFields: ['sex'],

        ruleTypes: {
            vital(name, rule, raw, context) {
                const { out, report, fieldsByName } = context;
                const value = vitalValue(raw, rule, fieldsByName.get(name));
                if (value.reason) {
                    count(report.vitalsEmptied, name, value.reason);
                }
                if (value.text) {
                    out[name] = value.text;
                }
                return;
            },
            bloodPressure(name, rule, raw, context) {
                const { out, report, fieldsByName } = context;
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
            },
            department(name, rule, raw, context) {
                const { cell, row, out, report, localMap } = context;
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
            },
            disposition(name, rule, raw, context) {
                applyDisposition(name, rule, raw, context);
            },
        },

        afterRow({ out, profile }) {
            if (VITAL_FIELDS.some((field) => (out[field] ?? '') !== '')) {
                out.vitals_reference = profile.vitalsReference;
            }
        },
        localMapFields: LOCAL_MAP_FIELDS,

        /** Source columns the core plan does not see: the age column and the station column of the disposition. */
        columnPlan({ use, profile }) {
            use(profile.age.column, 'age', 'transformed');
            for (const rule of Object.values(profile.fields)) {
                if (rule.type === 'disposition') {
                    use(rule.stationColumn, 'care_level', 'transformed');
                }
            }
        },
        internalColumns(profile) {
            return [[profile.age.birthDateColumn, ['age']]];
        },

        localMap: {
            sections: [
                { name: 'departments', valid: isDepartmentName },
                { name: 'stations', valid: (level) => CARE_LEVELS.includes(level) },
                { name: 'dispositions', valid: isValidDispositionTarget },
            ],
            /**
             * Report fields (`report.unmapped`) whose values the local map assigns, with the map section:
             * the `department` rule, the `disposition` rule and the care level from its station column.
             */
            reportSections(profile) {
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
            },
        },
    });
})(globalThis);
