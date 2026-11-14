/*
 * Page logic of the offline data preparation tool (#1051). Inlined by the server after
 * data-prep-core.js; reads its configuration from the JSON block `#data-prep-config`.
 *
 * The only network requests are the update check against `config.latestUrl` and the MTS catalog from
 * `config.catalogUrl` (#1157), the only URLs the Content Security Policy allows. Without an answer the
 * bundled catalog (`config.catalogs`) is used. Values from the source file are only ever set as text content.
 */
(function (root) {
    'use strict';

    const prep = root.EncounterDataPrep;
    const doc = root.document;
    const config = JSON.parse(doc.getElementById('data-prep-config').textContent);
    const t = (key) => config.messages[key] ?? key;
    const $ = (id) => doc.getElementById(id);
    const PREVIEW_ROWS = 10;
    const DB_NAME = 'encounter-data-prep';
    const DB_STORE = 'keys';
    const DB_ENTRY = 'current';
    const DB_MAP_ENTRY = 'local-map';

    const state = {
        blocked: false,
        key: null,
        localMap: null,
        // Plain map data (file format) behind state.localMap, changed by the editor (#1130).
        localMapData: null,
        localMapName: null,
        mapDirty: false,
        profile: null,
        table: null,
        result: null,
        // MTS catalogs used for runs: the bundled copy until the server's catalog arrives (#1157).
        catalogs: Object.fromEntries(
            Object.entries(config.catalogs).map(([id, catalog]) => [
                id,
                prep.parseCatalog(catalog),
            ]),
        ),
    };

    // ------------------------------------------------------------------ DOM helpers

    function element(tag, { text, className, attrs } = {}, children = []) {
        const node = doc.createElement(tag);
        if (text !== undefined) {
            node.textContent = String(text);
        }
        if (className) {
            node.className = className;
        }
        for (const [name, value] of Object.entries(attrs ?? {})) {
            node.setAttribute(name, value);
        }
        children.forEach((child) => node.append(child));
        return node;
    }

    function show(id, visible) {
        $(id).hidden = !visible;
    }

    function status(id, text, kind = 'info') {
        const node = $(id);
        node.textContent = text;
        node.dataset.kind = kind;
        node.hidden = text === '';
    }

    function table(headers, rows) {
        return element('table', { className: 'grid' }, [
            element('thead', {}, [
                element(
                    'tr',
                    {},
                    headers.map((h) => element('th', { text: h })),
                ),
            ]),
            element(
                'tbody',
                {},
                rows.map((cells) =>
                    element(
                        'tr',
                        {},
                        cells.map((c) => element('td', { text: c })),
                    ),
                ),
            ),
        ]);
    }

    function replace(id, ...nodes) {
        $(id).replaceChildren(...nodes);
    }

    function download(content, fileName, type) {
        const url = URL.createObjectURL(new Blob([content], { type }));
        const link = element('a', { attrs: { href: url, download: fileName } });
        doc.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    async function readFile(input) {
        const file = input.files?.[0];
        if (!file) {
            return null;
        }
        return { name: file.name, bytes: new Uint8Array(await file.arrayBuffer()) };
    }

    function today() {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    }

    // ------------------------------------------------------------------ update check

    async function checkForUpdate() {
        try {
            const response = await fetch(config.latestUrl, {
                method: 'GET',
                mode: 'cors',
                credentials: 'omit',
                cache: 'no-store',
                referrerPolicy: 'no-referrer',
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const latest = await response.json();
            const update = prep.updateState(config.version, latest);
            if (update === 'blocked') {
                state.blocked = true;
                status('update-status', `${t('updateBlocked')} ${latest.version}`, 'error');
            } else if (update === 'update') {
                status('update-status', `${t('updateAvailable')} ${latest.version}`, 'warning');
            } else {
                status('update-status', t('updateCurrent'), 'success');
            }
            if (update !== 'current' && typeof latest.downloadUrl === 'string') {
                const link = $('update-link');
                link.href = latest.downloadUrl;
                link.hidden = false;
            }
        } catch {
            status('update-status', t('updateUnavailable'), 'info');
        }
        refresh();
    }

    // ------------------------------------------------------------------ MTS catalog

    function catalogSummary(catalog) {
        const aliases =
            Object.keys(catalog.aliases.diagrams).length +
            Object.keys(catalog.aliases.indicators).length;
        return t('catalogCounts')
            .replace('{diagrams}', String(catalog.diagrams.length))
            .replace('{indicators}', String(catalog.indicators.length))
            .replace('{aliases}', String(aliases));
    }

    async function loadCatalog() {
        const bundled = Object.values(state.catalogs)[0];
        try {
            const response = await fetch(config.catalogUrl, {
                method: 'GET',
                mode: 'cors',
                credentials: 'omit',
                cache: 'no-store',
                referrerPolicy: 'no-referrer',
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const catalog = prep.parseCatalog(await response.json());
            if (!(catalog.id in state.catalogs)) {
                throw new Error(`Unknown catalog "${catalog.id}".`);
            }
            state.catalogs = { ...state.catalogs, [catalog.id]: catalog };
            status('catalog-status', `${t('catalogServer')} ${catalogSummary(catalog)}`, 'success');
        } catch {
            status('catalog-status', `${t('catalogBundled')} ${catalogSummary(bundled)}`, 'info');
        }
    }

    // ------------------------------------------------------------------ key file

    function openDb() {
        return new Promise((resolve, reject) => {
            const request = root.indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => request.result.createObjectStore(DB_STORE);
            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);
        });
    }

    async function withStore(mode, action) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(DB_STORE, mode);
            const request = action(transaction.objectStore(DB_STORE));
            transaction.oncomplete = () => {
                db.close();
                resolve(request.result);
            };
            transaction.onerror = () => reject(transaction.error);
        });
    }

    async function rememberKey(file) {
        try {
            await withStore('readwrite', (store) => store.put(file, DB_ENTRY));
            return true;
        } catch {
            status('key-status', t('keyRememberFailed'), 'warning');
            return false;
        }
    }

    async function forgetKey() {
        try {
            await withStore('readwrite', (store) => store.delete(DB_ENTRY));
        } catch {
            // nothing remembered
        }
        $('key-remember').checked = false;
        show('key-forget', false);
    }

    async function restoreKey() {
        try {
            const file = await withStore('readonly', (store) => store.get(DB_ENTRY));
            if (file) {
                await useKey(file, { remembered: true });
            }
        } catch {
            // browser storage unavailable: the key file is loaded per run
        }
    }

    async function useKey(content, { remembered = false } = {}) {
        try {
            state.key = await prep.loadKey(content);
        } catch (error) {
            state.key = null;
            status(
                'key-status',
                config.messages[`keyError_${error.message}`] ?? t('keyInvalid'),
                'error',
            );
            refresh();
            return;
        }
        const label = state.key.label || '—';
        status(
            'key-status',
            `${remembered ? t('keyRemembered') : t('keyLoaded')}: ${label} (${state.key.created}), ${t('keyCheck')} ${state.key.check.slice(0, 12)}…`,
            'success',
        );
        $('key-remember').checked = remembered;
        show('key-forget', remembered);
        refresh();
    }

    async function onGenerateKey() {
        const label = $('key-label').value.trim();
        if (label === '') {
            status('key-status', t('keyLabelRequired'), 'error');
            return;
        }
        const file = await prep.generateKeyFile(label, today());
        const slug = prep.catalogCode(label).replace(/_/g, '-') || 'hospital';
        download(
            `${JSON.stringify(file, null, 2)}\n`,
            `encounter-key-${slug}-${file.created}.json`,
            'application/json',
        );
        await useKey(file);
        status('key-status', `${t('keyGenerated')} ${$('key-status').textContent}`, 'success');
    }

    async function onKeyFile(event) {
        const file = await readFile(event.target);
        if (file === null) {
            return;
        }
        await useKey(prep.decodeBytes(file.bytes).text);
        if ($('key-remember').checked && state.key) {
            await rememberKey(state.key.file);
        }
    }

    async function onRememberChange(event) {
        if (!event.target.checked) {
            await forgetKey();
            return;
        }
        if (state.key && (await rememberKey(state.key.file))) {
            show('key-forget', true);
        }
    }

    // ------------------------------------------------------------------ local map

    function mapProfile() {
        return state.profile ?? config.profiles[0];
    }

    /**
     * Validates map data exactly like a loaded map file (prep.loadLocalMap) and makes it the current
     * map. Returns false, with the invalid entries in `statusId`, when the data is not valid.
     */
    function useLocalMap(data, { name, statusId = 'map-status' } = {}) {
        let loaded;
        try {
            loaded = prep.loadLocalMap(data, mapProfile());
        } catch (error) {
            const details = error.entries ? ` (${error.entries.join(', ')})` : '';
            status(statusId, `${t('mapInvalid')}${details}`, 'error');
            return false;
        }
        state.localMap = loaded;
        state.localMapData = loaded.data;
        state.localMapName = name ?? state.localMapName;
        renderMapStatus();
        return true;
    }

    function renderMapStatus() {
        if (state.localMap === null) {
            status('map-status', '', 'info');
            show('map-forget', false);
            refresh();
            return;
        }
        const { counts, label } = state.localMap;
        const text = `${t('mapLoaded')}: ${label || state.localMapName || '—'} — ${t('mapDepartments')} ${counts.departments}, ${t('mapStations')} ${counts.stations}, ${t('mapDispositions')} ${counts.dispositions}`;
        status(
            'map-status',
            state.mapDirty ? `${text}. ${t('mapUnsaved')}` : text,
            state.mapDirty ? 'warning' : 'success',
        );
        refresh();
    }

    async function onLocalMap(event) {
        const file = await readFile(event.target);
        if (file === null) {
            return;
        }
        let data;
        try {
            data = JSON.parse(prep.decodeBytes(file.bytes).text);
        } catch {
            data = null;
        }
        state.mapDirty = false;
        if (!useLocalMap(data, { name: file.name })) {
            state.localMap = null;
            state.localMapData = null;
            refresh();
        } else if ($('map-remember').checked) {
            await rememberMap();
        }
        invalidateResult();
        refresh();
    }

    function onSaveMap() {
        if (state.localMapData === null) {
            return;
        }
        const label = state.localMapData.hospital || state.key?.label || '';
        const slug = prep.catalogCode(label).replace(/_/g, '-') || 'hospital';
        download(
            prep.serializeLocalMap(state.localMapData),
            `encounter-local-map-${slug}-${today()}.json`,
            'application/json',
        );
        state.mapDirty = false;
        renderMapStatus();
        status('map-edit-status', t('mapSaved'), 'success');
    }

    async function rememberMap() {
        try {
            await withStore('readwrite', (store) => store.put(state.localMapData, DB_MAP_ENTRY));
            show('map-forget', true);
            return true;
        } catch {
            status('map-edit-status', t('mapRememberFailed'), 'warning');
            return false;
        }
    }

    async function forgetMap() {
        try {
            await withStore('readwrite', (store) => store.delete(DB_MAP_ENTRY));
        } catch {
            // nothing remembered
        }
        $('map-remember').checked = false;
        show('map-forget', false);
    }

    async function restoreMap() {
        try {
            const data = await withStore('readonly', (store) => store.get(DB_MAP_ENTRY));
            if (data && useLocalMap(data, { name: t('mapRememberedName') })) {
                $('map-remember').checked = true;
                show('map-forget', true);
            }
        } catch {
            // browser storage unavailable: the map file is loaded per run
        }
    }

    async function onMapRememberChange(event) {
        if (!event.target.checked) {
            await forgetMap();
            return;
        }
        if (state.localMapData !== null) {
            await rememberMap();
        }
    }

    // ------------------------------------------------------------------ local map editor

    /** Input controls for one unmapped value of a map section; values are read back by onApplyMap. */
    function mapEditor(section, value) {
        const attrs = { 'data-section': section, 'data-value': value };
        const option = (code, label) => element('option', { text: label, attrs: { value: code } });
        if (section === 'departments') {
            const input = element('input', {
                className: 'map-edit',
                attrs: {
                    ...attrs,
                    type: 'text',
                    maxlength: '64',
                    autocomplete: 'off',
                    'aria-label': `${t('mapEditDepartment')}: ${value}`,
                    placeholder: t('mapEditDepartment'),
                },
            });
            input.addEventListener('input', () => {
                const name = input.value;
                input.dataset.invalid = String(name !== '' && !prep.isDepartmentName(name));
            });
            return input;
        }
        if (section === 'stations') {
            return element(
                'select',
                {
                    className: 'map-edit',
                    attrs: { ...attrs, 'aria-label': `${t('mapEditCareLevel')}: ${value}` },
                },
                [
                    option('', '—'),
                    ...prep.CARE_LEVELS.map((level) => option(level, t(`careLevel_${level}`))),
                ],
            );
        }
        const disposition = element(
            'select',
            {
                className: 'map-edit',
                attrs: { ...attrs, 'aria-label': `${t('mapEditDisposition')}: ${value}` },
            },
            [
                option('', '—'),
                ...prep.DISPOSITIONS.map((code) => option(code, t(`disposition_${code}`))),
            ],
        );
        const careLevel = element(
            'select',
            {
                className: 'map-edit-care-level',
                attrs: { 'aria-label': `${t('mapEditCareLevel')}: ${value}`, disabled: '' },
            },
            [
                option('', t('mapEditCareLevelNone')),
                ...prep.CARE_LEVELS.map((level) => option(level, t(`careLevel_${level}`))),
            ],
        );
        disposition.addEventListener('change', () => {
            const allowed = prep.CARE_LEVEL_DISPOSITIONS.includes(disposition.value);
            careLevel.disabled = !allowed;
            if (!allowed) {
                careLevel.value = '';
            }
        });
        return element('span', { className: 'map-edit-pair' }, [disposition, careLevel]);
    }

    function unmappedTable(unmapped) {
        const sections = prep.localMapSections(state.profile);
        const rows = Object.entries(unmapped).flatMap(([field, values]) =>
            Object.entries(values).map(([value, n]) => ({ field, value, n })),
        );
        const editable = rows.some(({ field }) => sections[field] !== undefined);
        const headers = [t('reportField'), t('reportValue'), t('reportCount')];
        if (editable) {
            headers.push(t('mapEditColumn'));
        }
        const body = rows.map(({ field, value, n }) => {
            const cells = [field, value, n].map((c) => element('td', { text: c }));
            if (editable) {
                const section = sections[field];
                cells.push(
                    element(
                        'td',
                        {},
                        section === undefined
                            ? [element('span', { className: 'muted', text: t('mapEditProfile') })]
                            : [mapEditor(section, value)],
                    ),
                );
            }
            return element('tr', {}, cells);
        });
        const nodes = [
            element('table', { className: 'grid', attrs: { id: 'unmapped-table' } }, [
                element('thead', {}, [
                    element(
                        'tr',
                        {},
                        headers.map((h) => element('th', { text: h })),
                    ),
                ]),
                element('tbody', {}, body),
            ]),
        ];
        if (editable) {
            const apply = element('button', {
                text: t('mapApply'),
                attrs: { type: 'button', id: 'map-apply' },
            });
            apply.addEventListener('click', onApplyMap);
            nodes.push(
                element('p', { className: 'hint', text: t('mapEditHint') }),
                element('div', { className: 'row' }, [apply]),
            );
        }
        return nodes;
    }

    /** Collects the filled-in assignments of the report as `[section, source, target]` entries. */
    function collectMapEdits() {
        const edits = [];
        for (const control of doc.querySelectorAll('#unmapped-table .map-edit')) {
            const { section, value } = control.dataset;
            const chosen = control.value.trim() === '' ? '' : control.value;
            if (chosen === '') {
                continue;
            }
            if (section === 'dispositions') {
                const careLevel = control.parentElement.querySelector('.map-edit-care-level').value;
                edits.push([
                    section,
                    value,
                    careLevel === '' ? { disposition: chosen } : { disposition: chosen, careLevel },
                ]);
            } else {
                edits.push([section, value, chosen]);
            }
        }
        return edits;
    }

    async function onApplyMap() {
        const edits = collectMapEdits();
        if (edits.length === 0) {
            status('map-edit-status', t('mapEditNothing'), 'info');
            return;
        }
        let data = state.localMapData ?? prep.newLocalMapData(mapProfile(), state.key?.label ?? '');
        for (const [section, source, target] of edits) {
            data = prep.setLocalMapEntry(data, section, source, target);
        }
        const previous = state.mapDirty;
        state.mapDirty = true;
        if (!useLocalMap(data, { statusId: 'map-edit-status' })) {
            state.mapDirty = previous;
            return;
        }
        if ($('map-remember').checked) {
            await rememberMap();
        }
        await onRun();
        status(
            'map-edit-status',
            `${t('mapApplied')} ${edits.length}. ${t('mapUnsaved')}`,
            'warning',
        );
    }

    // ------------------------------------------------------------------ source file

    async function onSourceFile(event) {
        const file = await readFile(event.target);
        state.profile = null;
        state.table = null;
        invalidateResult();
        replace('column-preview');
        if (file === null) {
            refresh();
            return;
        }
        const { text, encoding } = prep.decodeBytes(file.bytes);
        const firstLine = prep.parseCsv(text.slice(0, 20000), ';')[0] ?? [];
        const header = firstLine.map((name) => name.replace(/\s+/g, ' ').trim());
        const profile = prep.detectProfile(header, config.profiles);
        if (profile === null) {
            status('source-status', t('profileUnknown'), 'error');
            refresh();
            return;
        }
        state.profile = profile;
        state.table = prep.readTable(text, profile.source.delimiter);
        status(
            'source-status',
            `${t('profileDetected')}: ${profile.label} v${profile.version} — ${state.table.rows.length} ${t('rows')}, ${encoding}`,
            'success',
        );
        renderColumnPlan(prep.columnPlan(state.table.header, profile));
        refresh();
    }

    function renderColumnPlan(plan) {
        const rows = plan.map((entry) => {
            const detail =
                entry.status === 'dropped'
                    ? [
                          t(`reason_${entry.reason}`),
                          entry.usedFor.length ? `${t('usedFor')} ${entry.usedFor.join(', ')}` : '',
                      ]
                          .filter(Boolean)
                          .join(' — ')
                    : entry.targets.join(', ');
            return [entry.column, t(`status_${entry.status}`), detail];
        });
        const counts = ['kept', 'transformed', 'dropped'].map(
            (kind) =>
                `${t(`status_${kind}`)}: ${plan.filter((entry) => entry.status === kind).length}`,
        );
        replace(
            'column-preview',
            element('p', { text: counts.join(' · ') }),
            table([t('columnSource'), t('columnStatus'), t('columnTarget')], rows),
        );
    }

    // ------------------------------------------------------------------ run and save

    function invalidateResult() {
        state.result = null;
        $('content-ack').checked = false;
        replace('report');
        replace('content-check');
        replace('output-preview');
        status('map-edit-status', '', 'info');
        show('result-section', false);
    }

    async function onRun() {
        if (!canRun()) {
            return;
        }
        $('run').disabled = true;
        status('run-status', t('running'), 'info');
        try {
            state.result = await prep.transform(state.table, {
                profile: state.profile,
                schema: config.schema,
                key: state.key,
                localMap: state.localMap ?? prep.emptyLocalMap(),
                catalogs: state.catalogs,
            });
            status('run-status', '', 'info');
            renderResult(state.result);
        } catch (error) {
            state.result = null;
            status('run-status', `${t('runFailed')}: ${error.message}`, 'error');
        }
        refresh();
    }

    function renderResult({ header, rows, report, content }) {
        const skipped = Object.entries(report.skipped).filter(([, n]) => n > 0);
        const summary = [
            [t('reportRowsRead'), report.rowsRead],
            [t('reportRowsWritten'), report.rowsWritten],
            ...skipped.map(([reason, n]) => [t(`skipped_${reason}`), n]),
            [t('reportAnchorAssessment'), report.anchorSources.assessment],
            [t('reportAnchorAdmission'), report.anchorSources.admission],
            [t('reportAgeFromBirthDate'), report.ageFromBirthDate],
            [t('reportAgeClamped'), report.ageClamped],
            [t('reportCareLevelUnknown'), report.careLevelUnknown],
            [t('reportPeriod'), `${report.firstAnchor ?? '—'} – ${report.lastAnchor ?? '—'}`],
        ];
        const emptied = [];
        for (const [bucket, label] of [
            [report.vitalsEmptied, t('reportVitalsEmptied')],
            [report.invalidValues, t('reportInvalidValues')],
        ]) {
            for (const [field, reasons] of Object.entries(bucket)) {
                for (const [reason, n] of Object.entries(reasons)) {
                    emptied.push([label, field, t(`invalid_${reason}`), n]);
                }
            }
        }
        const listRows = (bucket) =>
            Object.entries(bucket).flatMap(([field, values]) =>
                Object.entries(values).map(([value, n]) => [field, value, n]),
            );
        const unlisted = listRows(report.unlistedCatalogValues);
        const aliases = listRows(report.catalogAliases);
        replace(
            'report',
            table([t('reportItem'), t('reportCount')], summary),
            element('h3', { text: t('reportEmptiedTitle') }),
            emptied.length
                ? table(
                      [t('reportKind'), t('reportField'), t('reportReason'), t('reportCount')],
                      emptied,
                  )
                : element('p', { text: t('none') }),
            element('h3', { text: t('reportUnmappedTitle') }),
            element('p', { className: 'hint', text: t('reportUnmappedHint') }),
            ...(Object.keys(report.unmapped).length
                ? unmappedTable(report.unmapped)
                : [element('p', { text: t('none') })]),
            element('h3', { text: t('reportUnlistedTitle') }),
            element('p', { className: 'hint', text: t('reportUnlistedHint') }),
            unlisted.length
                ? table([t('reportField'), t('reportValue'), t('reportCount')], unlisted)
                : element('p', { text: t('none') }),
            element('h3', { text: t('reportAliasesTitle') }),
            element('p', { className: 'hint', text: t('reportAliasesHint') }),
            aliases.length
                ? table([t('reportField'), t('reportValue'), t('reportCount')], aliases)
                : element('p', { text: t('none') }),
        );

        replace(
            'content-check',
            content.passed
                ? element('p', {
                      className: 'status',
                      text: t('contentPassed'),
                      attrs: { 'data-kind': 'success' },
                  })
                : element('div', {}, [
                      element('p', {
                          className: 'status',
                          text: t('contentFailed'),
                          attrs: { 'data-kind': 'error' },
                      }),
                      table(
                          [
                              t('reportField'),
                              t('columnSource'),
                              t('reportKind'),
                              t('reportCount'),
                              t('firstLine'),
                          ],
                          content.findings.map((f) => [
                              f.field,
                              f.column ?? '—',
                              t(`content_${f.kind}`),
                              f.count,
                              f.firstLine,
                          ]),
                      ),
                  ]),
        );
        show('content-ack-row', !content.passed);

        replace(
            'output-preview',
            element('p', {
                text: `${t('previewOf')} ${Math.min(PREVIEW_ROWS, rows.length)} / ${rows.length}`,
            }),
            element('div', { className: 'scroll' }, [table(header, rows.slice(0, PREVIEW_ROWS))]),
        );
        show('result-section', true);
    }

    function onSave() {
        if (!canSave()) {
            return;
        }
        const file = prep.buildExchangeFile(state.result, {
            profile: state.profile,
            schema: config.schema,
            toolVersion: config.version,
            keyCheck: state.key.check,
        });
        download(
            file,
            prep.exchangeFileName(state.result, state.profile),
            'text/csv;charset=utf-8',
        );
        status('save-status', t('saved'), 'success');
    }

    // ------------------------------------------------------------------ state

    function canRun() {
        return (
            !state.blocked && state.key !== null && state.table !== null && state.profile !== null
        );
    }

    function canSave() {
        return (
            canRun() &&
            state.result !== null &&
            state.result.rows.length > 0 &&
            (state.result.content.passed || $('content-ack').checked)
        );
    }

    function refresh() {
        $('run').disabled = !canRun();
        $('save').disabled = !canSave();
        $('map-save').disabled = state.localMapData === null;
    }

    function init() {
        $('key-generate').addEventListener('click', onGenerateKey);
        $('key-file').addEventListener('change', onKeyFile);
        $('key-remember').addEventListener('change', onRememberChange);
        $('key-forget').addEventListener('click', forgetKey);
        $('map-file').addEventListener('change', onLocalMap);
        $('map-save').addEventListener('click', onSaveMap);
        $('map-remember').addEventListener('change', onMapRememberChange);
        $('map-forget').addEventListener('click', forgetMap);
        $('source-file').addEventListener('change', onSourceFile);
        $('run').addEventListener('click', onRun);
        $('save').addEventListener('click', onSave);
        $('content-ack').addEventListener('change', refresh);
        refresh();
        restoreKey();
        restoreMap();
        checkForUpdate();
        loadCatalog();
    }

    init();
})(globalThis);
