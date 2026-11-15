// Tests of the line chart marker rule (#1284). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    lineMarkers,
    SINGLE_POINT_MARKER_SIZE,
    seriesPointCount,
} from '../../../assets/lib/chart-markers.js';

describe('lineMarkers', () => {
    it('shows a marker when the chart has exactly one point', () => {
        assert.deepEqual(lineMarkers(1), { size: SINGLE_POINT_MARKER_SIZE });
        assert.equal(SINGLE_POINT_MARKER_SIZE, 4);
    });

    it('draws no markers for two or more points and for no points', () => {
        assert.deepEqual(lineMarkers(2), { size: 0 });
        assert.deepEqual(lineMarkers(24), { size: 0 });
        assert.deepEqual(lineMarkers(0), { size: 0 });
    });

    it('keeps the chart own marker options', () => {
        assert.deepEqual(lineMarkers(12, { hover: { size: 4 } }), { hover: { size: 4 }, size: 0 });
        assert.deepEqual(lineMarkers(1, { hover: { size: 4 } }), { hover: { size: 4 }, size: 4 });
    });
});

describe('seriesPointCount', () => {
    it('counts the points of the longest series', () => {
        assert.equal(seriesPointCount([{ data: [1] }, { data: [1, 2, 3] }]), 3);
        assert.equal(seriesPointCount([{ data: [[0.5, 7]] }]), 1);
    });

    it('is 0 without series or data', () => {
        assert.equal(seriesPointCount([]), 0);
        assert.equal(seriesPointCount(undefined), 0);
        assert.equal(seriesPointCount([{}]), 0);
    });
});
