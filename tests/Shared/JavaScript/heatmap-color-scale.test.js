// Tests of the shared heatmap colour scale (#1283). Run with `npm run test:js`.
//
// Cells with 0 cases get the empty colour. The non-zero values are sorted; the breaks are the minimum,
// the values at the 20/40/60/80 % positions (index = round(p × (n − 1))) and the maximum, without
// duplicates. Each interval gets the next colour from green to red.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { buildHeatmapColorScale } from '../../../assets/lib/build-analysis-heatmap-options.js';

const EMPTY = '#f1f5f9';
const GREEN = '#2fb344';
const LIME = '#74b816';
const YELLOW = '#f59f00';
const ORANGE = '#f76707';
const RED = '#d63939';

describe('buildHeatmapColorScale', () => {
    it('has only the empty range when no cell has cases', () => {
        assert.deepEqual(buildHeatmapColorScale([[0, 0], [0]]), {
            min: 0,
            max: 1,
            ranges: [{ from: 0, to: 0, color: EMPTY }],
        });
    });

    it('uses the middle colour when all non-zero cells are equal', () => {
        assert.deepEqual(buildHeatmapColorScale([[5, 0], [5]]), {
            min: 0,
            max: 5,
            ranges: [
                { from: 0, to: 0, color: EMPTY },
                { from: 5, to: 5, color: YELLOW },
            ],
        });
    });

    it('splits 1 to 10 at 3, 5, 6 and 8 and ignores zeros', () => {
        // n = 10, positions round(1.8) = 2, round(3.6) = 4, round(5.4) = 5, round(7.2) = 7
        // → values 3, 5, 6, 8; breaks 1, 3, 5, 6, 8, 10; five intervals, one colour each.
        const matrix = [
            [1, 2, 3, 0],
            [4, 5, 6, 0],
            [7, 8, 9, 10],
        ];

        assert.deepEqual(buildHeatmapColorScale(matrix), {
            min: 0,
            max: 10,
            ranges: [
                { from: 0, to: 0, color: EMPTY },
                { from: 1, to: 3, color: GREEN },
                { from: 3, to: 5, color: LIME },
                { from: 5, to: 6, color: YELLOW },
                { from: 6, to: 8, color: ORANGE },
                { from: 8, to: 10, color: RED },
            ],
        });
    });

    it('drops duplicate breaks', () => {
        // n = 5, every percentile position (1, 2, 2, 3) holds 1 → breaks 1 and 9, one interval.
        assert.deepEqual(buildHeatmapColorScale([[1, 1, 1, 1, 9]]), {
            min: 0,
            max: 9,
            ranges: [
                { from: 0, to: 0, color: EMPTY },
                { from: 1, to: 9, color: GREEN },
            ],
        });
    });

    it('takes the empty colour from the caller', () => {
        const scale = buildHeatmapColorScale([[0, 2]], '#333333');

        assert.deepEqual(scale.ranges[0], { from: 0, to: 0, color: '#333333' });
    });
});
