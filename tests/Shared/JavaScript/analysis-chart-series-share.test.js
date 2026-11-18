// Tests of grouped bars for shares within each series (#1346). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { buildAnalysisChartOptions } from '../../../assets/lib/build-analysis-chart-options.js';

// Reference comparison (#1346): A has 75 % / 25 %, the other hospitals 50 % / 50 %.
const shares = {
    chartType: 'bar',
    labels: ['X', 'Y'],
    series: [
        { name: 'Hospital A', data: [75, 25] },
        { name: 'Other hospitals in scope', data: [50, 50] },
    ],
    percentScale: true,
    barGrouped: true,
};

describe('analysis chart: shares within each series', () => {
    it('draws shares within each series as grouped bars', () => {
        const options = buildAnalysisChartOptions({ ...shares, seriesShare: true });

        assert.equal(options.chart.stacked, false);
    });

    it('keeps stacking shares of the total', () => {
        const options = buildAnalysisChartOptions(shares);

        assert.equal(options.chart.stacked, true);
    });
});
