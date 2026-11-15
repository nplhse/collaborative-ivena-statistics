// Tests of the sidebar filter (ADR 035, #1248). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    filterTree,
    highlightRanges,
    normalize,
    searchWords,
} from '../../../assets/lib/navigation-filter.js';

const leaf = (id, label, section) => ({ id, label, section, children: [] });
const group = (id, label, section, children) => ({ id, label, section, children });

// A small copy of the statistics sidebar: an untitled first section, Allocations with nested Insights,
// Capacity & closures with a group of parts.
const sections = [
    { id: 'overview', label: '' },
    { id: 'allocations', label: 'Zuweisungen' },
    { id: 'capacity', label: 'Kapazität & Schließungen' },
];
const tree = () => [
    leaf('dashboard', 'Übersicht', 'overview'),
    leaf('top-lists', 'Top-Listen', 'overview'),
    group('insights', 'Insights', 'allocations', [
        leaf('insights-overview', 'Übersicht', 'allocations'),
        group('dimensions', 'Dimensionen', 'allocations', [
            leaf('specialities', 'Fachbereiche', 'allocations'),
            leaf('occasions', 'Anlässe', 'allocations'),
            leaf('assignments', 'Zuweisung', 'allocations'),
        ]),
    ]),
    leaf('closed', 'Notzuweisungen', 'capacity'),
    group('closures', 'Schließungen', 'capacity', [
        leaf('closures-overview', 'Übersicht', 'capacity'),
        leaf('closures-duration', 'Dauer und zeitliche Belastung', 'capacity'),
    ]),
];

const sorted = (set) => [...set].sort();

describe('normalisation', () => {
    it('ignores case and accents and spells ß as ss', () => {
        assert.equal(normalize('Anlässe'), 'anlasse');
        assert.equal(normalize('ÜBERSICHT'), 'ubersicht');
        assert.equal(normalize('Schließungen'), 'schliessungen');
    });

    it('splits a term into words and drops blanks', () => {
        assert.deepEqual(searchWords('  Insights   Fach '), ['insights', 'fach']);
        assert.deepEqual(searchWords('   '), []);
    });
});

describe('highlighting', () => {
    it('marks the matching part in the original label', () => {
        assert.deepEqual(highlightRanges('Fachbereiche', ['fach']), [[0, 4]]);
        assert.deepEqual(
            highlightRanges('Anlässe', ['anlass']),
            [[0, 6]],
            'ä counts as one character',
        );
    });

    it('maps ß back to one character and merges overlapping ranges', () => {
        assert.deepEqual(highlightRanges('Schließungen', ['liess']), [[3, 7]], 'ss covers the ß');
        assert.deepEqual(highlightRanges('Notzuweisungen', ['zuweis', 'weisung']), [[3, 12]]);
    });
});

describe('filtering', () => {
    it('shows everything when the term is empty', () => {
        const result = filterTree(tree(), sections, '  ');

        assert.equal(result.active, false);
        assert.equal(result.visible.size, 0);
    });

    it('finds a deep entry by its own name and opens the whole path', () => {
        const result = filterTree(tree(), sections, 'Fachbereich');

        assert.deepEqual(sorted(result.visible), ['dimensions', 'insights', 'specialities']);
        assert.deepEqual(sorted(result.open), ['dimensions', 'insights']);
        assert.deepEqual(sorted(result.sections), ['allocations']);
        assert.deepEqual(result.highlights.get('specialities'), [[0, 11]]);
        assert.equal(result.links, 1);
    });

    it('keeps all entries of a matching group visible and open', () => {
        const result = filterTree(tree(), sections, 'dimension');

        assert.deepEqual(sorted(result.visible), [
            'assignments',
            'dimensions',
            'insights',
            'occasions',
            'specialities',
        ]);
        assert.equal(result.links, 3);
    });

    it('finds the same name in different places', () => {
        const result = filterTree(tree(), sections, 'ubersicht');

        assert.deepEqual(sorted(result.visible), [
            'closures',
            'closures-overview',
            'dashboard',
            'insights',
            'insights-overview',
        ]);
        assert.deepEqual(sorted(result.sections), ['allocations', 'capacity', 'overview']);
        assert.equal(result.links, 3);
    });

    it('narrows several words along the path, one of them in the entry itself', () => {
        const result = filterTree(tree(), sections, 'insights ubersicht');

        assert.deepEqual(sorted(result.visible), ['insights', 'insights-overview']);
        assert.equal(
            result.highlights.has('insights'),
            false,
            'Insights alone does not contain "ubersicht"',
        );
        assert.deepEqual(result.highlights.get('insights-overview'), [[0, 9]]);
    });

    it('keeps a whole section when its title matches', () => {
        const result = filterTree(tree(), sections, 'kapazitat');

        assert.deepEqual(sorted(result.visible), [
            'closed',
            'closures',
            'closures-duration',
            'closures-overview',
        ]);
        assert.deepEqual(sorted(result.open), ['closures']);
        assert.deepEqual(sorted(result.sections), ['capacity']);
    });

    it('requires every word on the path of the entry', () => {
        const result = filterTree(tree(), sections, 'zuweisungen dauer');

        assert.deepEqual(
            sorted(result.visible),
            [],
            'Dauer is under Kapazität, not under Zuweisungen',
        );
    });

    it('returns nothing visible when no label matches', () => {
        const result = filterTree(tree(), sections, 'xyz');

        assert.equal(result.active, true);
        assert.equal(result.visible.size, 0);
        assert.equal(result.sections.size, 0);
        assert.equal(result.links, 0);
    });
});
