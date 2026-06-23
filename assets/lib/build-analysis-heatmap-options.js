import { ANALYSIS_CHART_HEIGHT_PROFILE_EXPLORER } from './analysis-chart-height-profile.js';
import { buildHeatmapSeries } from './build-heatmap-series.js';

const ANALYSIS_CHART_FONT_FAMILY =
    "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

/**
 * @returns {string}
 */
function resolveAnalysisChartFontFamily() {
    if (typeof document !== 'undefined' && document.body) {
        const bodyFont = getComputedStyle(document.body).fontFamily;
        if (bodyFont && bodyFont !== 'inherit') {
            return bodyFont;
        }
    }

    return ANALYSIS_CHART_FONT_FAMILY;
}

const HEATMAP_EMPTY_COLOR = '#f1f5f9';
const HEATMAP_COLORS = ['#2fb344', '#74b816', '#f59f00', '#f76707', '#d63939'];

/**
 * @param {number[][]} matrix
 */
export function buildHeatmapColorScale(matrix) {
    const values = matrix
        .flat()
        .filter((value) => value > 0)
        .sort((a, b) => a - b);
    const ranges = [{ from: 0, to: 0, color: HEATMAP_EMPTY_COLOR }];

    if (!values.length) {
        return { min: 0, max: 1, ranges };
    }

    const min = values[0];
    const max = values[values.length - 1];

    if (min === max) {
        ranges.push({ from: min, to: max, color: HEATMAP_COLORS[2] });
        return { min: 0, max, ranges };
    }

    const valueAtPercentile = (percentile) => {
        const index = Math.min(
            values.length - 1,
            Math.max(0, Math.round(percentile * (values.length - 1))),
        );

        return values[index];
    };

    const breaks = [min];
    [0.2, 0.4, 0.6, 0.8].forEach((percentile) => {
        const value = valueAtPercentile(percentile);
        if (value > breaks[breaks.length - 1]) {
            breaks.push(value);
        }
    });
    if (max > breaks[breaks.length - 1]) {
        breaks.push(max);
    }

    const intervalCount = breaks.length - 1;
    for (let i = 0; i < intervalCount; i += 1) {
        const colorIndex = Math.min(
            HEATMAP_COLORS.length - 1,
            Math.floor((i / intervalCount) * HEATMAP_COLORS.length),
        );
        ranges.push({
            from: breaks[i],
            to: breaks[i + 1],
            color: HEATMAP_COLORS[colorIndex],
        });
    }

    return { min: 0, max, ranges };
}

/**
 * @param {Record<string, unknown>} data
 * @param {{ chartHeightProfile?: string }} [buildOptions]
 * @returns {import('apexcharts').ApexOptions | null}
 */
export function buildAnalysisHeatmapOptions(data, buildOptions = {}) {
    const rowLabels = Array.isArray(data.rowLabels) ? data.rowLabels : [];
    const columnLabels = Array.isArray(data.columnLabels) ? data.columnLabels : [];
    const matrix = Array.isArray(data.matrix) ? data.matrix : [];

    if (rowLabels.length === 0 || columnLabels.length === 0) {
        return null;
    }

    const colorScale = buildHeatmapColorScale(matrix);
    const series = buildHeatmapSeries(columnLabels, rowLabels, matrix);
    const chartFontFamily = resolveAnalysisChartFontFamily();
    const profile = buildOptions.chartHeightProfile ?? '';
    const chartHeight =
        profile === ANALYSIS_CHART_HEIGHT_PROFILE_EXPLORER
            ? Math.min(640, Math.max(360, rowLabels.length * 32 + 112))
            : Math.min(520, Math.max(280, rowLabels.length * 28 + 96));

    return {
        chart: {
            type: 'heatmap',
            height: chartHeight,
            toolbar: { show: false },
            fontFamily: chartFontFamily,
        },
        colors: [HEATMAP_COLORS[0]],
        plotOptions: {
            heatmap: {
                radius: 2,
                enableShades: false,
                colorScale: {
                    min: colorScale.min,
                    max: Math.max(colorScale.max, 1),
                    ranges: colorScale.ranges,
                },
            },
        },
        dataLabels: { enabled: false },
        legend: { show: false },
        series,
        xaxis: {
            type: 'category',
            labels: {
                style: {
                    fontFamily: chartFontFamily,
                },
            },
        },
        yaxis: {
            labels: {
                style: {
                    fontFamily: chartFontFamily,
                },
            },
        },
        tooltip: {
            y: {
                formatter: (value) => {
                    const numeric = typeof value === 'number' ? value : Number(value);
                    if (!Number.isFinite(numeric)) {
                        return '—';
                    }

                    if (data.percentScale === true || data.valueFormat === 'percent') {
                        const rounded = Math.round(numeric * 100) / 100;
                        return `${rounded}%`;
                    }

                    return numeric.toLocaleString();
                },
            },
        },
    };
}
