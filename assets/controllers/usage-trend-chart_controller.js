import { Controller } from '@hotwired/stimulus';
import { lineMarkers } from '../lib/chart-markers.js';
import { applyChartNonce, loadApexCharts } from '../lib/load-apexcharts.js';

const COLORS = ['#206bc4', '#2fb344', '#ae3ec9'];
const SERIES = ['dau', 'wau', 'mau'];

/**
 * Daily, weekly and monthly users per day on the admin dashboard (#1322). Days without a counted value are null
 * and leave a gap; a value without neighbours gets its own marker so it stays visible.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        chart: Object,
        labels: Object,
    };

    static targets = ['chart'];

    connect() {
        this.chartInstance = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderChart(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        if (this.chartInstance) {
            this.chartInstance.destroy();
            this.chartInstance = null;
        }
    }

    async renderChart(generation) {
        if (!this.hasChartTarget || !this.chartValue) {
            return;
        }

        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration || !this.hasChartTarget) {
            return;
        }

        this.chartInstance = new ApexCharts(this.chartTarget, applyChartNonce(this.buildOptions()));
        await this.chartInstance.render();
    }

    buildOptions() {
        const data = this.chartValue;
        const labels = data.labels ?? [];
        const names = this.labelsValue ?? {};
        const series = SERIES.map((key) => ({
            name: names[key] ?? key.toUpperCase(),
            data: data[key] ?? [],
        }));

        return {
            chart: {
                type: 'line',
                height: 280,
                toolbar: { show: false },
                zoom: { enabled: false },
                fontFamily: 'inherit',
                background: 'transparent',
            },
            series,
            colors: COLORS,
            stroke: { width: 2, curve: 'straight' },
            markers: lineMarkers(labels.length, { discrete: isolatedPoints(series) }),
            dataLabels: { enabled: false },
            xaxis: {
                categories: labels,
                tickAmount: 15,
                labels: { rotate: -45, hideOverlappingLabels: true, style: { fontSize: '11px' } },
            },
            yaxis: {
                min: 0,
                forceNiceScale: true,
                labels: { formatter: (value) => Math.round(value).toLocaleString() },
            },
            legend: { show: true, position: 'top', horizontalAlign: 'right', fontSize: '12px' },
            grid: { strokeDashArray: 4 },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: (value) =>
                        value === null || value === undefined
                            ? '–'
                            : Math.round(value).toLocaleString(),
                },
            },
        };
    }
}

/**
 * Markers for values whose neighbours are both missing; ApexCharts draws no line through them.
 *
 * @param {{ data: (number|null)[] }[]} series
 * @returns {{ seriesIndex: number, dataPointIndex: number, size: number, fillColor: string, strokeColor: string }[]}
 */
export function isolatedPoints(series) {
    const markers = [];
    series.forEach((entry, seriesIndex) => {
        entry.data.forEach((value, index) => {
            const missing = (i) => entry.data[i] === null || entry.data[i] === undefined;
            if (!missing(index) && missing(index - 1) && missing(index + 1)) {
                markers.push({
                    seriesIndex,
                    dataPointIndex: index,
                    size: 4,
                    fillColor: COLORS[seriesIndex],
                    strokeColor: COLORS[seriesIndex],
                });
            }
        });
    });

    return markers;
}
