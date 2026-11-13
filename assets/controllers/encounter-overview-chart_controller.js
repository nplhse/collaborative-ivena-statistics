import { Controller } from '@hotwired/stimulus';
import { applyChartNonce, loadApexCharts } from '../lib/load-apexcharts.js';

/**
 * Bar chart of the encounter overview (#1055). Suppressed counts (ADR 012) arrive as null and are
 * drawn as gaps, never as their true value.
 *
 * @param {{categories: string[], series: {name: string, data: (number|null)[], color?: string}[], stacked?: boolean, horizontal?: boolean, colors?: string[]}} chart
 * @returns {Record<string, unknown>}
 */
export function buildEncounterChartOptions(chart, isDark = false) {
    if (chart.type === 'boxPlot') {
        return buildBoxPlotOptions(chart, isDark);
    }

    const series = chart.series ?? [];
    const distributed =
        series.length === 1 && Array.isArray(chart.colors) && chart.colors.length > 0;

    return {
        chart: {
            type: 'bar',
            height: Math.max(
                220,
                (chart.horizontal ? 34 : 0) * (chart.categories ?? []).length + 80,
            ),
            stacked: Boolean(chart.stacked),
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit',
            background: 'transparent',
            foreColor: isDark ? '#adb5bd' : '#495057',
            animations: { enabled: false },
        },
        theme: { mode: isDark ? 'dark' : 'light' },
        plotOptions: {
            bar: {
                horizontal: Boolean(chart.horizontal),
                distributed,
                borderRadius: 3,
                borderRadiusWhenStacked: 'last',
            },
        },
        series: series.map((serie) => ({ name: serie.name, data: serie.data })),
        colors: distributed ? chart.colors : series.map((serie) => serie.color).filter(Boolean),
        dataLabels: { enabled: false },
        legend: {
            show: series.length > 1,
            position: 'top',
            horizontalAlign: 'left',
            fontSize: '12px',
        },
        xaxis: { categories: chart.categories ?? [], title: { text: '' } },
        yaxis: {
            title: { text: '' },
            labels: {
                formatter: (value) =>
                    typeof value === 'number' ? String(Math.round(value)) : value,
            },
        },
        grid: {
            strokeDashArray: 4,
            borderColor: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)',
        },
        tooltip: { shared: true, intersect: false },
    };
}

/**
 * Horizontal box plot (#1055): one box per MTS category, y = [P10, Q1, median, Q3, P90] in minutes.
 * Whiskers are P10 and P90, never minimum and maximum (single visits).
 *
 * @param {{series: {name: string, data: {x: string, y: number[]}[]}[], unit?: string}} chart
 * @returns {Record<string, unknown>}
 */
export function buildBoxPlotOptions(chart, isDark = false) {
    const series = chart.series ?? [];
    const rows = series[0]?.data?.length ?? 0;
    const unit = chart.unit ? ` ${chart.unit}` : '';
    const round = (value) =>
        typeof value === 'number' ? `${Math.round(value * 10) / 10}${unit}` : value;

    return {
        chart: {
            type: 'boxPlot',
            height: Math.max(180, 44 * rows + 70),
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit',
            background: 'transparent',
            foreColor: isDark ? '#adb5bd' : '#495057',
            animations: { enabled: false },
        },
        theme: { mode: isDark ? 'dark' : 'light' },
        plotOptions: {
            bar: { horizontal: true, barHeight: '55%' },
            boxPlot: { colors: { upper: '#4299e1', lower: '#a5c8f0' } },
        },
        series: series.map((serie) => ({ name: serie.name, type: 'boxPlot', data: serie.data })),
        dataLabels: { enabled: false },
        legend: { show: false },
        xaxis: { title: { text: '' }, tickAmount: 4, labels: { formatter: round } },
        yaxis: { title: { text: '' } },
        grid: {
            strokeDashArray: 4,
            borderColor: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)',
        },
        tooltip: { shared: false, y: { formatter: round } },
    };
}

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { chart: Object };

    connect() {
        this.chartInstance = null;
        this._generation = (this._generation ?? 0) + 1;
        void this.render(this._generation);
    }

    disconnect() {
        this._generation = (this._generation ?? 0) + 1;
        if (this.chartInstance) {
            this.chartInstance.destroy();
            this.chartInstance = null;
        }
    }

    async render(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._generation) {
            return;
        }

        const isDark = document.body.getAttribute('data-bs-theme') === 'dark';
        this.chartInstance = new ApexCharts(
            this.element,
            applyChartNonce(buildEncounterChartOptions(this.chartValue, isDark)),
        );
        await this.chartInstance.render();
    }
}
