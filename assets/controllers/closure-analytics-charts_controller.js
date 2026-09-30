import { Controller } from '@hotwired/stimulus';
import {
    buildHeatmapSeries,
    heatmapColumnIndexFromSeriesIndex,
} from '../lib/build-heatmap-series.js';
import { formatChartMonthLabel } from '../lib/format-chart-month-label.js';
import { loadApexCharts } from '../lib/load-apexcharts.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { payload: Object };
    static targets = ['timeSeriesChart', 'heatmapChart'];

    async connect() {
        this.charts = [];
        this.generation = (this.generation ?? 0) + 1;
        const generation = this.generation;
        const ApexCharts = await loadApexCharts();
        if (generation !== this.generation) return;

        const payload = this.payloadValue ?? {};
        if (this.hasTimeSeriesChartTarget) {
            await this.renderTimeSeries(ApexCharts, payload.timeSeries ?? {});
        }
        if (this.hasHeatmapChartTarget) {
            await this.renderHeatmap(ApexCharts, payload.heatmap ?? {});
        }
    }

    async renderTimeSeries(ApexCharts, payload) {
        const chart = new ApexCharts(this.timeSeriesChartTarget, {
            chart: { type: 'line', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
            series: [
                {
                    name: payload.seriesLabel ?? 'Closure duration',
                    data: payload.closedHours ?? [],
                },
            ],
            colors: ['#206bc4'],
            xaxis: {
                categories: payload.labels ?? [],
                labels: { formatter: (value) => formatChartMonthLabel(value) },
            },
            yaxis: {
                min: 0,
                labels: {
                    formatter: (value) =>
                        `${Number(value).toFixed(1)} ${payload.tooltipSuffix ?? ''}`,
                },
            },
            tooltip: {
                y: {
                    formatter: (value) =>
                        `${Number(value).toFixed(1)} ${payload.tooltipSuffix ?? ''}`,
                },
            },
            stroke: { width: 3, curve: 'smooth' },
            dataLabels: { enabled: false },
            legend: { show: false },
        });
        this.charts.push(chart);
        await chart.render();
    }

    async renderHeatmap(ApexCharts, payload) {
        const labels = payload.columnLabels ?? [];
        const chart = new ApexCharts(this.heatmapChartTarget, {
            chart: {
                type: 'heatmap',
                height: 420,
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: buildHeatmapSeries(labels, payload.rowLabels ?? [], payload.matrix ?? []),
            colors: ['#206bc4'],
            plotOptions: {
                heatmap: {
                    radius: 2,
                    enableShades: true,
                    shadeIntensity: 0.8,
                },
            },
            dataLabels: { enabled: false },
            tooltip: {
                custom: ({ seriesIndex, dataPointIndex, w }) => {
                    const slot = heatmapColumnIndexFromSeriesIndex(seriesIndex, labels.length);
                    const value = w.config.series[seriesIndex].data[dataPointIndex].y;
                    return `<div class="p-2"><strong>${Number(value).toFixed(1)} ${payload.durationLabel ?? 'hours'}</strong><br>${labels[slot] ?? ''}</div>`;
                },
            },
        });
        this.charts.push(chart);
        await chart.render();
    }

    disconnect() {
        this.generation = (this.generation ?? 0) + 1;
        (this.charts ?? []).forEach((chart) => chart.destroy());
        this.charts = [];
    }
}
