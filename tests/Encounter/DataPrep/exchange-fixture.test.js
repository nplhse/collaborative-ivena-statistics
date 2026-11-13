// Contract between the data preparation tool and the encounter import (#1052). Run with `npm run test:js`.
//
// Runs the tool core on the synthetic ORBIS-like sample and keeps the canonical exchange file it writes
// in `fixtures/orbis-mts-sample.ed_visit.csv`. The PHP test `DataPrepToolOutputImportTest` uploads that
// file through the import and expects no file or row rejects. Never edit the fixture by hand: when the
// tool output changes, this test writes the new file and fails once; review and commit it.
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { describe, it } from 'node:test';

import '../../../src/Encounter/UI/DataPrep/js/data-prep-core.js';

const prep = globalThis.EncounterDataPrep;
const base = new URL('../../../src/Encounter/UI/DataPrep/', import.meta.url);
const fixtures = new URL('./fixtures/', import.meta.url);
const json = (url) => JSON.parse(readFileSync(url, 'utf8'));

const profile = json(new URL('profiles/orbis-mts.json', base));
const schema = json(new URL('schema/ed_visit.v1.json', base));
const catalogs = {
    'mts-de': json(
        new URL('../../../src/Encounter/Application/Catalog/mts-de.json', import.meta.url),
    ),
};
const vectors = json(new URL('fingerprint-vectors.json', fixtures));
const output = new URL('orbis-mts-sample.ed_visit.csv', fixtures);

// The released tool version (`app.encounter.data_prep.version`), as the tool writes it into the manifest.
const toolVersion = readFileSync(
    new URL('../../../config/packages/app.yaml', import.meta.url),
    'utf8',
).match(/data_prep:\s*\n\s*version:\s*'(\d+\.\d+\.\d+)'/)[1];

describe('exchange file for the import contract test', () => {
    it('is the current output of the tool for the synthetic sample', async () => {
        const { text } = prep.decodeBytes(readFileSync(new URL('orbis-mts-sample.csv', fixtures)));
        const result = await prep.transform(prep.readTable(text, profile.source.delimiter), {
            profile,
            schema,
            key: await prep.loadKey({
                format: 'encounter-key',
                version: 1,
                label: 'Test',
                created: '2026-11-12',
                key: vectors.key,
                check: vectors.keyCheck,
            }),
            localMap: prep.loadLocalMap(
                readFileSync(new URL('local-map.sample.json', fixtures), 'utf8'),
                profile,
            ),
            catalogs,
        });
        const file = prep.buildExchangeFile(result, {
            profile,
            schema,
            toolVersion,
            keyCheck: vectors.keyCheck,
        });

        let committed = null;
        try {
            committed = readFileSync(output, 'utf8');
        } catch {
            // first run: no fixture yet
        }
        if (committed !== file) {
            writeFileSync(output, file);
        }
        assert.equal(
            committed,
            file,
            'The tool output changed: fixtures/orbis-mts-sample.ed_visit.csv was regenerated; review and commit it.',
        );
    });
});
