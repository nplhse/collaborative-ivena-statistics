import { isDarkTheme } from './chart-theme.js';

/**
 * Data colours that depend on the colour scheme (#1278).
 *
 * Axis text, grid lines, cell borders and tooltips are themed globally in
 * `assets/styles/charts.css`. The values here are the few colours ApexCharts needs as plain colour
 * values in its options (heatmap ranges, series colours, bar strokes), where CSS variables and
 * light-dark() do not work. Light values are the ones the charts used before.
 *
 * @typedef {{ emptyCell: string, neutralSeries: string, surface: string }} ChartColors
 */

/** @type {Readonly<ChartColors>} */
export const LIGHT_CHART_COLORS = Object.freeze({
    emptyCell: '#f1f5f9',
    neutralSeries: '#495057',
    surface: '#ffffff',
});

/** @type {Readonly<ChartColors>} */
export const DARK_CHART_COLORS = Object.freeze({
    emptyCell: '#333333',
    neutralSeries: '#adb5bd',
    surface: '#262626',
});

/**
 * @param {Element} element the chart element or one of its ancestors
 * @returns {Readonly<ChartColors>}
 */
export function chartColors(element) {
    return isDarkTheme(element) ? DARK_CHART_COLORS : LIGHT_CHART_COLORS;
}
