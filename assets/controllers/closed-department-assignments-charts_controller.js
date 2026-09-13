import { Controller } from '@hotwired/stimulus';
import { buildHeatmapSeries } from '../lib/build-heatmap-series.js';
import { formatChartMonthLabel } from '../lib/format-chart-month-label.js';
import { loadApexCharts } from '../lib/load-apexcharts.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
        seriesMode: { type: String, default: 'count' },
    };

    static targets = [
        'timeSeriesChart',
        'heatmapChart',
        'transportChart',
        'seriesModeCount',
        'seriesModeShare',
    ];

    connect() {
        this.instances = [];
        this.heatmapInstance = null;
        this.timeSeriesInstance = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderAll(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.instances.forEach((chart) => chart.destroy());
        this.instances = [];
        this.heatmapInstance = null;
        this.timeSeriesInstance = null;
    }

    async renderAll(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const payload = this.payloadValue ?? {};

        this.renderTimeSeries(ApexCharts, payload.timeSeries ?? {}, generation);
        this.renderHeatmap(ApexCharts, payload.heatmap ?? {}, generation);
        this.renderTransportChart(ApexCharts, payload.transport ?? {}, generation);
        this.syncSeriesModeButtons();
    }

    setSeriesMode(event) {
        const mode = event.params.mode;
        if (!mode || mode === this.seriesModeValue) {
            return;
        }

        this.seriesModeValue = mode;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderTimeSeriesOnly(this._renderGeneration);
        this.syncSeriesModeButtons();
    }

    async renderTimeSeriesOnly(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        this.renderTimeSeries(ApexCharts, this.payloadValue?.timeSeries ?? {}, generation);
    }

    syncSeriesModeButtons() {
        if (this.hasSeriesModeCountTarget) {
            this.seriesModeCountTarget.classList.toggle('active', this.seriesModeValue === 'count');
        }
        if (this.hasSeriesModeShareTarget) {
            this.seriesModeShareTarget.classList.toggle('active', this.seriesModeValue === 'share');
        }
    }

    renderTimeSeries(ApexCharts, series, generation) {
        if (generation !== this._renderGeneration || !this.hasTimeSeriesChartTarget) {
            return;
        }

        if (this.timeSeriesInstance) {
            const previous = this.timeSeriesInstance;
            previous.destroy();
            this.timeSeriesInstance = null;
            this.instances = this.instances.filter((chart) => chart !== previous);
        }

        const labels = series.labels ?? [];
        const values =
            this.seriesModeValue === 'share'
                ? (series.shares ?? []).map((value) => (value === null ? 0 : value))
                : (series.counts ?? []);
        const isShare = this.seriesModeValue === 'share';

        const chart = new ApexCharts(this.timeSeriesChartTarget, {
            chart: {
                type: 'line',
                height: 240,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
            },
            series: [{ name: isShare ? 'Share' : 'Count', data: values }],
            colors: ['#206bc4'],
            xaxis: {
                categories: labels,
                tickAmount: Math.min(6, labels.length),
                labels: {
                    rotate: 0,
                    hideOverlappingLabels: true,
                    formatter: (value) => formatChartMonthLabel(value),
                },
            },
            tooltip: {
                x: {
                    formatter: (value) => formatChartMonthLabel(value),
                },
                y: {
                    formatter: (value) =>
                        isShare ? `${Number(value).toFixed(1)}%` : Math.round(value).toString(),
                },
            },
            stroke: { curve: 'smooth', width: 3 },
            markers: { size: 0 },
            dataLabels: { enabled: false },
            yaxis: {
                min: 0,
                forceNiceScale: true,
                decimalsInFloat: isShare ? 1 : 0,
                labels: {
                    formatter: (value) =>
                        isShare ? `${Number(value).toFixed(1)}%` : Math.round(value).toString(),
                },
            },
            legend: { show: false },
        });

        chart.render();
        this.timeSeriesInstance = chart;
        this.instances.push(chart);
    }

    renderTransportChart(ApexCharts, transport, generation) {
        if (generation !== this._renderGeneration || !this.hasTransportChartTarget) {
            return;
        }

        const labels = transport.labels ?? [];
        const closedShares = (transport.closedShares ?? []).map((value) => value ?? 0);
        const totalShares = (transport.totalShares ?? []).map((value) => value ?? 0);
        if (!labels.length) {
            return;
        }

        const formatPercent = (value) => `${Number(value).toFixed(1)}%`;

        const chart = new ApexCharts(this.transportChartTarget, {
            chart: {
                type: 'bar',
                height: 320,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
                stacked: false,
            },
            series: [
                { name: transport.closedLabel ?? 'Closed', data: closedShares },
                { name: transport.totalLabel ?? 'Total', data: totalShares },
            ],
            colors: ['#206bc4', '#495057'],
            plotOptions: {
                bar: {
                    horizontal: true,
                    borderRadius: 2,
                    barHeight: '75%',
                },
            },
            xaxis: {
                categories: labels,
                labels: {
                    formatter: formatPercent,
                },
            },
            tooltip: {
                y: {
                    formatter: formatPercent,
                },
            },
            dataLabels: { enabled: false },
            legend: { show: true, position: 'top' },
        });

        chart.render();
        this.instances.push(chart);
    }

    buildHeatmapColorScale(matrix) {
        const EMPTY = '#f1f5f9';
        const COLORS = ['#2fb344', '#74b816', '#f59f00', '#f76707', '#d63939'];

        const values = matrix
            .flat()
            .filter((value) => value > 0)
            .sort((a, b) => a - b);
        const ranges = [{ from: 0, to: 0, color: EMPTY }];

        if (!values.length) {
            return { min: 0, max: 1, ranges };
        }

        const min = values[0];
        const max = values[values.length - 1];

        if (min === max) {
            ranges.push({ from: min, to: max, color: COLORS[2] });
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
                COLORS.length - 1,
                Math.floor((i / intervalCount) * COLORS.length),
            );
            ranges.push({
                from: breaks[i],
                to: breaks[i + 1],
                color: COLORS[colorIndex],
            });
        }

        return { min: 0, max, ranges };
    }

    renderHeatmap(ApexCharts, heatmap, generation) {
        if (generation !== this._renderGeneration || !this.hasHeatmapChartTarget) {
            return;
        }

        if (this.heatmapInstance) {
            this.heatmapInstance.destroy();
            this.heatmapInstance = null;
        }

        const rowLabels = heatmap.rowLabels ?? [];
        const columnLabels = heatmap.columnLabels ?? [];
        const matrix = heatmap.matrix ?? [];
        if (!rowLabels.length || !columnLabels.length) {
            return;
        }

        const colorScale = this.buildHeatmapColorScale(matrix);
        const series = buildHeatmapSeries(columnLabels, rowLabels, matrix);

        const chart = new ApexCharts(this.heatmapChartTarget, {
            chart: {
                type: 'heatmap',
                height: 450,
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            colors: ['#2fb344'],
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
            xaxis: { type: 'category' },
        });

        chart.render();
        this.heatmapInstance = chart;
    }
}
