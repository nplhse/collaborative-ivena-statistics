// Tests of the month range of the access overview chart (#1001). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { lastMonths } from '../../../assets/lib/access-trend-range.js';

const months = ['2025-10', '2025-11', '2025-12', '2026-01', '2026-02'];

describe('lastMonths', () => {
    it('keeps the newest months, oldest first', () => {
        assert.deepEqual(lastMonths(months, 2), ['2026-01', '2026-02']);
    });

    it('keeps every month when the range is longer than the history', () => {
        assert.deepEqual(lastMonths(months, 12), months);
    });

    it('keeps every month for "all" (0)', () => {
        assert.deepEqual(lastMonths(months, 0), months);
    });

    it('returns a copy, not the input', () => {
        assert.notEqual(lastMonths(months, 0), months);
    });

    it('returns no months for missing input', () => {
        assert.deepEqual(lastMonths(undefined, 12), []);
    });
});
