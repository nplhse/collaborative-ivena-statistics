import { Controller } from '@hotwired/stimulus';
import { applyChartNonce, loadApexCharts } from '../lib/load-apexcharts.js';

const COLORS = {
    handled: '#2fb344',
    retried: '#f59f00',
    failed: '#d63939',
    duration: '#206bc4',
    backlog: '#6c757d',
};

/**
 * Milliseconds as "850 ms" or "2.4 s".
 *
 * @param {number|null} ms
 * @returns {string}
 */
export function formatDuration(ms) {
    if (ms === null || ms === undefined) {
        return '–';
    }

    return ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${Math.round(ms)} ms`;
}

/**
 * Messenger counters and backlog over time (ADR 019): stacked bars of handled, retried and failed messages, and
 * lines for the average duration and the queue backlog.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        chart: Object,
        labels: Object,
    };

    static targets = ['messages', 'timing'];

    connect() {
        this.charts = [];
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.render(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.charts.forEach((chart) => chart.destroy());
        this.charts = [];
    }

    async render(generation) {
        const data = this.chartValue;
        if (!data || !Array.isArray(data.buckets) || data.buckets.length === 0) {
            return;
        }

        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const timestamps = data.buckets.map((bucket) => Date.parse(bucket));
        const pairs = (values) => values.map((value, index) => [timestamps[index], value]);
        const labels = this.labelsValue ?? {};
        const base = this.baseOptions(data.granularity);

        const messages = applyChartNonce({
            ...base,
            chart: { ...base.chart, type: 'bar', stacked: true },
            series: [
                { name: labels.handled ?? 'Handled', data: pairs(data.handled) },
                { name: labels.retried ?? 'Retried', data: pairs(data.retried) },
                { name: labels.failed ?? 'Failed', data: pairs(data.failed) },
            ],
            colors: [COLORS.handled, COLORS.retried, COLORS.failed],
            plotOptions: { bar: { columnWidth: '80%' } },
            yaxis: {
                min: 0,
                forceNiceScale: true,
                labels: { formatter: (value) => `${Math.round(value)}` },
            },
            tooltip: { ...base.tooltip, y: { formatter: (value) => `${value ?? 0}` } },
        });

        const timing = applyChartNonce({
            ...base,
            chart: { ...base.chart, type: 'line' },
            series: [
                { name: labels.duration ?? 'Average duration', data: pairs(data.duration) },
                { name: labels.backlog ?? 'Backlog', data: pairs(data.backlog) },
            ],
            colors: [COLORS.duration, COLORS.backlog],
            stroke: { width: 2, curve: 'straight' },
            markers: { size: data.buckets.length === 1 ? 4 : 0 },
            yaxis: [
                {
                    min: 0,
                    title: { text: labels.duration ?? 'Average duration' },
                    labels: { formatter: formatDuration },
                },
                {
                    min: 0,
                    opposite: true,
                    forceNiceScale: true,
                    title: { text: labels.backlog ?? 'Backlog' },
                    labels: { formatter: (value) => `${Math.round(value)}` },
                },
            ],
            tooltip: {
                ...base.tooltip,
                y: [
                    { formatter: formatDuration },
                    {
                        formatter: (value) =>
                            value === null || value === undefined ? '–' : `${value}`,
                    },
                ],
            },
        });

        const created = [];
        if (this.hasMessagesTarget) {
            created.push(new ApexCharts(this.messagesTarget, messages));
        }
        if (this.hasTimingTarget) {
            created.push(new ApexCharts(this.timingTarget, timing));
        }
        this.charts = created;
        await Promise.all(created.map((chart) => chart.render()));
    }

    baseOptions(granularity) {
        const isDark =
            document.body.classList.contains('ea-dark-scheme') ||
            document.body.getAttribute('data-bs-theme') === 'dark';

        return {
            chart: {
                height: 260,
                toolbar: { show: false },
                zoom: { enabled: false },
                fontFamily: 'inherit',
                background: 'transparent',
                foreColor: isDark ? '#adb5bd' : '#495057',
                animations: { enabled: false },
            },
            theme: { mode: isDark ? 'dark' : 'light' },
            dataLabels: { enabled: false },
            xaxis: {
                type: 'datetime',
                labels: {
                    datetimeUTC: false,
                    datetimeFormatter: {
                        year: 'yyyy',
                        month: 'MM.yyyy',
                        day: 'dd.MM.',
                        hour: 'HH:mm',
                    },
                },
            },
            legend: { show: true, position: 'top', horizontalAlign: 'left', fontSize: '12px' },
            grid: {
                strokeDashArray: 4,
                borderColor: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)',
            },
            tooltip: {
                shared: true,
                intersect: false,
                theme: isDark ? 'dark' : 'light',
                x: { format: granularity === 'day' ? 'dd.MM.yyyy' : 'dd.MM.yyyy HH:mm' },
            },
        };
    }
}
