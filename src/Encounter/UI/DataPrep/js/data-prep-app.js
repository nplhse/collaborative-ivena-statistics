/*
 * Page logic of the offline data preparation tool (#1051). Inlined by the server after
 * data-prep-core.js; reads its configuration from the JSON block `#data-prep-config`.
 *
 * The only network request is the update check against `config.latestUrl` (also the only URL the
 * Content Security Policy allows). Values from the source file are only ever set as text content.
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

    const state = {
        blocked: false,
        key: null,
        localMap: null,
        profile: null,
        table: null,
        result: null,
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

    async function onLocalMap(event) {
        const file = await readFile(event.target);
        if (file === null) {
            return;
        }
        try {
            const profile = state.profile ?? config.profiles[0];
            state.localMap = prep.loadLocalMap(prep.decodeBytes(file.bytes).text, profile);
            const { counts, label } = state.localMap;
            status(
                'map-status',
                `${t('mapLoaded')}: ${label || file.name} — ${t('mapDepartments')} ${counts.departments}, ${t('mapStations')} ${counts.stations}, ${t('mapDispositions')} ${counts.dispositions}`,
                'success',
            );
        } catch (error) {
            state.localMap = null;
            const details = error.entries ? ` (${error.entries.join(', ')})` : '';
            status('map-status', `${t('mapInvalid')}${details}`, 'error');
        }
        invalidateResult();
        refresh();
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
                catalogs: config.catalogs,
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
        const unmapped = listRows(report.unmapped);
        const unlisted = listRows(report.unlistedCatalogValues);
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
            unmapped.length
                ? table([t('reportField'), t('reportValue'), t('reportCount')], unmapped)
                : element('p', { text: t('none') }),
            element('h3', { text: t('reportUnlistedTitle') }),
            element('p', { className: 'hint', text: t('reportUnlistedHint') }),
            unlisted.length
                ? table([t('reportField'), t('reportValue'), t('reportCount')], unlisted)
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
    }

    function init() {
        $('key-generate').addEventListener('click', onGenerateKey);
        $('key-file').addEventListener('change', onKeyFile);
        $('key-remember').addEventListener('change', onRememberChange);
        $('key-forget').addEventListener('click', forgetKey);
        $('map-file').addEventListener('change', onLocalMap);
        $('source-file').addEventListener('change', onSourceFile);
        $('run').addEventListener('click', onRun);
        $('save').addEventListener('click', onSave);
        $('content-ack').addEventListener('change', refresh);
        refresh();
        restoreKey();
        checkForUpdate();
    }

    init();
})(globalThis);
