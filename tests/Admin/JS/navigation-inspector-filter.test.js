// Tests of the admin navigation tree filter (#1237). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { filterInspectorTree } from '../../../assets/lib/navigation-inspector-filter.js';

const node = (id, text, { issue = false, hidden = false, onPath = false } = {}, children = []) => ({
    id,
    text,
    issue,
    hidden,
    onPath,
    children,
});

// Capacity group of the statistics sidebar for a user without ROLE_CLOSURE_BETA, with the duration part of
// Closures on the path of the checked route.
const tree = () => [
    node('assignments', 'Notzuweisungen app_stats_closed_department_assignments'),
    node('closures', 'Schließungen closures', { issue: true, hidden: true, onPath: true }, [
        node('closures-overview', 'Übersicht app_stats_closure_analytics', {
            issue: true,
            hidden: true,
        }),
        node('closures-duration', 'Dauer app_stats_closure_analytics_interval', {
            issue: true,
            hidden: true,
            onPath: true,
        }),
    ]),
];

const sorted = (set) => [...set].sort();

describe('filterInspectorTree', () => {
    it('keeps everything without filters and counts every node', () => {
        const result = filterInspectorTree(tree());

        assert.equal(result.total, 4);
        assert.equal(result.shown, 4);
    });

    it('keeps the parents of a match and ignores case and accents', () => {
        const result = filterInspectorTree(tree(), { query: 'UBERSICHT' });

        assert.deepEqual(sorted(result.keep), ['closures', 'closures-overview']);
    });

    it('searches the routes in the text too', () => {
        assert.deepEqual(sorted(filterInspectorTree(tree(), { query: 'interval' }).keep), [
            'closures',
            'closures-duration',
        ]);
    });

    it('shows only deviations', () => {
        assert.deepEqual(sorted(filterInspectorTree(tree(), { onlyIssues: true }).keep), [
            'closures',
            'closures-duration',
            'closures-overview',
        ]);
    });

    it('drops hidden entries', () => {
        assert.deepEqual(sorted(filterInspectorTree(tree(), { showHidden: false }).keep), [
            'assignments',
        ]);
    });

    it('shows only the active path, whatever else is selected', () => {
        const result = filterInspectorTree(tree(), {
            onlyPath: true,
            query: 'nothing',
            showHidden: false,
        });

        assert.deepEqual(sorted(result.keep), ['closures', 'closures-duration']);
        assert.equal(result.shown, 2);
        assert.equal(result.total, 4);
    });
});
