import { Controller } from '@hotwired/stimulus';
import { loadApexCharts } from '../lib/load-apexcharts.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
        heatmapMode: { type: String, default: 'dayTime' },
    };

    static targets = [
        'timeSeriesChart',
        'ageGroupsChart',
        'transportTimeChart',
        'heatmapChart',
        'heatmapModeDayTime',
        'heatmapModeShift',
    ];

    connect() {
        this.instances = [];
        this.heatmapInstance = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderAll(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.instances.forEach((chart) => chart.destroy());
        this.instances = [];
        this.heatmapInstance = null;
    }

    async renderAll(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const payload = this.payloadValue ?? {};

        if (this.hasTimeSeriesChartTarget) {
            this.renderTimeSeriesChart(
                ApexCharts,
                this.timeSeriesChartTarget,
                payload.timeSeries?.labels ?? [],
                payload.timeSeries?.values ?? [],
                generation,
            );
        }

        if (this.hasAgeGroupsChartTarget) {
            const labels = JSON.parse(this.ageGroupsChartTarget.dataset.ageGroupLabels ?? '[]');
            const values = JSON.parse(this.ageGroupsChartTarget.dataset.ageGroupValues ?? '[]');
            this.renderBarChart(
                ApexCharts,
                this.ageGroupsChartTarget,
                labels,
                values,
                { type: 'bar', height: 260, horizontal: false },
                generation,
            );
        }

        if (this.hasTransportTimeChartTarget) {
            const labels = JSON.parse(this.transportTimeChartTarget.dataset.transportTimeLabels ?? '[]');
            const values = JSON.parse(this.transportTimeChartTarget.dataset.transportTimeValues ?? '[]');
            this.renderBarChart(
                ApexCharts,
                this.transportTimeChartTarget,
                labels,
                values,
                { type: 'bar', height: 280, horizontal: true, barHeight: '70%' },
                generation,
            );
        }

        this.renderHeatmap(ApexCharts, this.currentHeatmapPayload(payload), generation);
        this.syncHeatmapModeButtons();
    }

    setHeatmapMode(event) {
        this.heatmapModeValue = event.params.mode;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderHeatmapOnly(this._renderGeneration);
    }

    async renderHeatmapOnly(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const payload = this.payloadValue ?? {};
        this.renderHeatmap(ApexCharts, this.currentHeatmapPayload(payload), generation);
        this.syncHeatmapModeButtons();
    }

    heatmapModeValueChanged() {
        if (!this.isConnected) {
            return;
        }

        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderHeatmapOnly(this._renderGeneration);
    }

    currentHeatmapPayload(payload) {
        return this.heatmapModeValue === 'shift'
            ? (payload.heatmapShift ?? {})
            : (payload.heatmapDayTime ?? {});
    }

    syncHeatmapModeButtons() {
        if (this.hasHeatmapModeDayTimeTarget) {
            this.heatmapModeDayTimeTarget.classList.toggle('active', this.heatmapModeValue === 'dayTime');
        }
        if (this.hasHeatmapModeShiftTarget) {
            this.heatmapModeShiftTarget.classList.toggle('active', this.heatmapModeValue === 'shift');
        }
    }

    movingAverage(values, windowSize) {
        return values.map((_, index) => {
            const start = Math.max(0, index - windowSize + 1);
            const slice = values.slice(start, index + 1);
            const sum = slice.reduce((total, value) => total + value, 0);

            return Math.round((sum / slice.length) * 10) / 10;
        });
    }

    renderTimeSeriesChart(ApexCharts, element, labels, values, generation) {
        if (generation !== this._renderGeneration || !element) {
            return;
        }

        const windowSize = Math.min(6, Math.max(3, Math.floor(values.length / 5) || 3));
        const showMovingAverage = values.length >= windowSize;
        const movingAverageLabel = element.dataset.movingAverageLabel ?? 'Moving average';
        const series = [{ name: 'Cases', data: values }];

        if (showMovingAverage) {
            series.push({
                name: movingAverageLabel,
                data: this.movingAverage(values, windowSize),
            });
        }

        const chart = new ApexCharts(element, {
            chart: {
                type: 'line',
                height: 240,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
            },
            series,
            colors: showMovingAverage ? ['#206bc4', '#6c757d'] : ['#206bc4'],
            xaxis: {
                categories: labels,
                tickAmount: Math.min(6, labels.length),
                labels: {
                    rotate: 0,
                    hideOverlappingLabels: true,
                    formatter: (value) => (
                        typeof value === 'string' && value.includes('-')
                            ? value.slice(2).replace('-', '/')
                            : value
                    ),
                },
            },
            stroke: showMovingAverage
                ? { curve: 'smooth', width: [3, 2], dashArray: [0, 6] }
                : { curve: 'smooth', width: 3 },
            markers: { size: 0 },
            dataLabels: { enabled: false },
            legend: {
                show: showMovingAverage,
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '12px',
                markers: { size: 4 },
            },
        });

        chart.render();
        this.instances.push(chart);
    }

    renderBarChart(ApexCharts, element, labels, values, options, generation) {
        if (generation !== this._renderGeneration || !element) {
            return;
        }

        const chartConfig = {
            chart: {
                type: options.type,
                height: options.height,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
            },
            series: [{ name: 'Cases', data: values }],
            xaxis: {
                categories: labels,
                tickAmount: options.sparseTicks ? Math.min(6, labels.length) : undefined,
                labels: {
                    rotate: options.labelRotate ?? 0,
                    hideOverlappingLabels: true,
                    formatter: (value) => (
                        options.sparseTicks && typeof value === 'string' && value.includes('-')
                            ? value.slice(2).replace('-', '/')
                            : value
                    ),
                },
            },
            dataLabels: { enabled: false },
            stroke: {
                curve: 'smooth',
                width: 0,
            },
            fill: { opacity: 1 },
        };

        if (options.type === 'bar') {
            chartConfig.plotOptions = {
                bar: {
                    horizontal: options.horizontal ?? false,
                    borderRadius: 3,
                    columnWidth: options.horizontal ? undefined : '55%',
                    barHeight: options.horizontal ? (options.barHeight ?? '70%') : undefined,
                },
            };
        }

        const chart = new ApexCharts(element, chartConfig);

        chart.render();
        this.instances.push(chart);
    }

    buildHeatmapColorScale(matrix) {
        const EMPTY = '#f1f5f9';
        const COLORS = ['#2fb344', '#74b816', '#f59f00', '#f76707', '#d63939'];

        const values = matrix.flat().filter((value) => value > 0).sort((a, b) => a - b);
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
        const colorScale = this.buildHeatmapColorScale(matrix);

        const series = columnLabels.map((colLabel, colIndex) => ({
            name: colLabel,
            data: rowLabels.map((rowLabel, rowIndex) => ({
                x: rowLabel,
                y: matrix[rowIndex]?.[colIndex] ?? 0,
            })),
        }));

        const chart = new ApexCharts(this.heatmapChartTarget, {
            chart: {
                type: 'heatmap',
                height: 300,
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
        this.instances.push(chart);
    }
}
