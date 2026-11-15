// Tests of the scheme-dependent chart data colours (#1278). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    chartColors,
    DARK_CHART_COLORS,
    LIGHT_CHART_COLORS,
} from '../../../assets/lib/chart-colors.js';

// Element stub whose nearest `[data-bs-theme]` ancestor carries `theme` (null: no attribute).
const elementIn = (theme) => ({
    closest: () => (theme === null ? null : { getAttribute: () => theme }),
});

describe('chartColors', () => {
    it('returns the dark colours in dark mode', () => {
        assert.equal(chartColors(elementIn('dark')), DARK_CHART_COLORS);
    });

    it('returns the light colours in light mode and without a theme attribute', () => {
        assert.equal(chartColors(elementIn('light')), LIGHT_CHART_COLORS);
        assert.equal(chartColors(elementIn(null)), LIGHT_CHART_COLORS);
    });

    it('keeps the light colours the charts used before', () => {
        assert.deepEqual(LIGHT_CHART_COLORS, {
            emptyCell: '#f1f5f9',
            neutralSeries: '#495057',
            surface: '#ffffff',
        });
    });

    it('defines the same keys for both schemes', () => {
        assert.deepEqual(Object.keys(DARK_CHART_COLORS), Object.keys(LIGHT_CHART_COLORS));
    });
});
