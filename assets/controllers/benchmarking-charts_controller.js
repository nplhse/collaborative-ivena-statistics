import { Controller } from '@hotwired/stimulus';
import {
    buildHeatmapSeries,
    heatmapColumnIndexFromSeriesIndex,
} from '../lib/build-heatmap-series.js';
import { loadApexCharts } from '../lib/load-apexcharts.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
        primaryLabel: String,
        comparisonLabel: String,
        heatmapMode: { type: String, default: 'dayTime' },
    };

    static targets = [
        'ageGroupsChart',
        'transportTimesChart',
        'caseDistributionHeatmap',
        'heatmapModeDayTime',
        'heatmapModeShift',
    ];

    connect() {
        this.instances = [];
        this.heatmapInstance = null;
        this._activeHeatmapTooltipContent = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.ensureHeatmapTooltipElement();
        void this.renderAll(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.instances.forEach((chart) => chart.destroy());
        this.instances = [];
        this.heatmapInstance = null;
        this._activeHeatmapTooltipContent = null;
        this.heatmapTooltipElement?.remove();
        this.heatmapTooltipElement = null;
    }

    ensureHeatmapTooltipElement() {
        if (this.heatmapTooltipElement) {
            return;
        }

        this.heatmapTooltipElement = document.createElement('div');
        this.heatmapTooltipElement.className = 'benchmark-delta-heatmap__tooltip d-none';
        this.heatmapTooltipElement.setAttribute('role', 'tooltip');
        document.body.appendChild(this.heatmapTooltipElement);
    }

    hideHeatmapTooltip() {
        this.heatmapTooltipElement?.classList.add('d-none');
    }

    showHeatmapTooltip(event, content) {
        this.ensureHeatmapTooltipElement();

        const tooltip = this.heatmapTooltipElement;
        tooltip.innerHTML = content;
        tooltip.classList.remove('d-none');

        const offset = 12;
        const rect = tooltip.getBoundingClientRect();
        let left = event.clientX + offset;
        let top = event.clientY + offset;

        if (left + rect.width > window.innerWidth - 8) {
            left = event.clientX - rect.width - offset;
        }
        if (top + rect.height > window.innerHeight - 8) {
            top = event.clientY - rect.height - offset;
        }

        tooltip.style.left = `${Math.max(8, left)}px`;
        tooltip.style.top = `${Math.max(8, top)}px`;
    }

    buildHeatmapTooltipContent({
        weekday,
        bucket,
        primaryLabel,
        comparisonLabel,
        primaryShare,
        comparisonShare,
        delta,
    }) {
        const formatPercent = (value) => this.formatPercent(value);
        const formatDelta = (value) => this.formatDelta(value);
        const deltaClass =
            delta > 0 ? 'text-danger' : delta < 0 ? 'text-success' : 'text-secondary';

        return (
            '' +
            `<div class="fw-medium mb-1">${weekday} · ${bucket}</div>` +
            `<div class="small text-secondary">${primaryLabel}: ${formatPercent(primaryShare)}%</div>` +
            `<div class="small text-secondary">${comparisonLabel}: ${formatPercent(comparisonShare)}%</div>` +
            `<div class="fw-bold mt-1 ${deltaClass}">Δ ${formatDelta(delta)}%</div>`
        );
    }

    buildDeltaHeatmapColorScale(maxAbsDelta) {
        const neutral = '#f1f5f9';
        const max = Math.max(maxAbsDelta, 0.1);
        const neutralBand = 0.05;

        return {
            min: -max,
            max,
            ranges: [
                { from: -max, to: -max * 0.5, color: '#2fb344' },
                { from: -max * 0.5, to: -neutralBand, color: '#74b816' },
                { from: -neutralBand, to: neutralBand, color: neutral },
                { from: neutralBand, to: max * 0.5, color: '#f76707' },
                { from: max * 0.5, to: max, color: '#d63939' },
            ],
        };
    }

    setHeatmapMode(event) {
        const mode = event.params.mode;
        if (!mode || mode === this.heatmapModeValue) {
            return;
        }

        this.heatmapModeValue = mode;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderHeatmapOnly(this._renderGeneration);
    }

    async renderHeatmapOnly(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const payload = this.payloadValue ?? {};
        this.renderDeltaHeatmap(ApexCharts, this.currentHeatmapPayload(payload), generation);
        this.syncHeatmapModeButtons();
    }

    async renderAll(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const payload = this.payloadValue ?? {};
        const primaryLabel = this.primaryLabelValue || 'Primary';
        const comparisonLabel = this.comparisonLabelValue || 'Comparison';

        if (this.hasAgeGroupsChartTarget && payload.ageGroups?.labels?.length) {
            this.renderGroupedBar(
                ApexCharts,
                this.ageGroupsChartTarget,
                payload.ageGroups.labels,
                [
                    { name: primaryLabel, data: payload.ageGroups.primary ?? [] },
                    { name: comparisonLabel, data: payload.ageGroups.comparison ?? [] },
                ],
                { height: 280, horizontal: false },
                generation,
            );
        }

        if (this.hasTransportTimesChartTarget && payload.transportTimes?.labels?.length) {
            this.renderGroupedBar(
                ApexCharts,
                this.transportTimesChartTarget,
                payload.transportTimes.labels,
                [
                    { name: primaryLabel, data: payload.transportTimes.primary ?? [] },
                    { name: comparisonLabel, data: payload.transportTimes.comparison ?? [] },
                ],
                { height: 280, horizontal: true, barHeight: '70%' },
                generation,
            );
        }

        this.renderDeltaHeatmap(ApexCharts, this.currentHeatmapPayload(payload), generation);
        this.syncHeatmapModeButtons();
    }

    currentHeatmapPayload(payload) {
        return this.heatmapModeValue === 'shift'
            ? (payload.heatmapShift ?? {})
            : (payload.heatmapDayTime ?? {});
    }

    syncHeatmapModeButtons() {
        if (this.hasHeatmapModeDayTimeTarget) {
            this.heatmapModeDayTimeTarget.classList.toggle(
                'active',
                this.heatmapModeValue === 'dayTime',
            );
        }
        if (this.hasHeatmapModeShiftTarget) {
            this.heatmapModeShiftTarget.classList.toggle(
                'active',
                this.heatmapModeValue === 'shift',
            );
        }
    }

    formatPercent(value) {
        const rounded = Math.round(value * 10) / 10;

        return rounded.toFixed(1).replace('.', ',');
    }

    formatDelta(value) {
        const rounded = Math.round(value * 10) / 10;
        const formatted = Math.abs(rounded).toFixed(1).replace('.', ',');
        if (rounded > 0) {
            return `+${formatted}`;
        }
        if (rounded < 0) {
            return `-${formatted}`;
        }

        return formatted;
    }

    renderDeltaHeatmap(ApexCharts, heatmap, generation) {
        if (generation !== this._renderGeneration || !this.hasCaseDistributionHeatmapTarget) {
            return;
        }

        this.hideHeatmapTooltip();
        this._activeHeatmapTooltipContent = null;

        if (this.heatmapInstance) {
            this.heatmapInstance.destroy();
            this.heatmapInstance = null;
        }

        const rowLabels = heatmap.rowLabels ?? [];
        const columnLabels = heatmap.columnLabels ?? [];
        const matrix = heatmap.matrix ?? [];
        const primaryShares = heatmap.primaryShares ?? [];
        const comparisonShares = heatmap.comparisonShares ?? [];

        const container = this.caseDistributionHeatmapTarget;
        container.replaceChildren();

        if (!rowLabels.length || !columnLabels.length) {
            return;
        }

        const colorScale = this.buildDeltaHeatmapColorScale(heatmap.maxAbsDelta ?? 0);
        const primaryLabel = this.primaryLabelValue || 'Primary';
        const comparisonLabel = this.comparisonLabelValue || 'Comparison';

        const series = buildHeatmapSeries(columnLabels, rowLabels, matrix);

        const controller = this;
        const columnCount = columnLabels.length;

        const chart = new ApexCharts(container, {
            chart: {
                type: 'heatmap',
                height: 300,
                toolbar: { show: false },
                fontFamily: 'inherit',
                events: {
                    dataPointMouseEnter(event, _chartContext, config) {
                        const rowIndex = config.dataPointIndex;
                        const colIndex = heatmapColumnIndexFromSeriesIndex(
                            config.seriesIndex,
                            columnCount,
                        );
                        const content = controller.buildHeatmapTooltipContent({
                            weekday: rowLabels[rowIndex] ?? '',
                            bucket: columnLabels[colIndex] ?? '',
                            primaryLabel,
                            comparisonLabel,
                            primaryShare: primaryShares[rowIndex]?.[colIndex] ?? 0,
                            comparisonShare: comparisonShares[rowIndex]?.[colIndex] ?? 0,
                            delta: matrix[rowIndex]?.[colIndex] ?? 0,
                        });

                        controller._activeHeatmapTooltipContent = content;
                        controller.showHeatmapTooltip(event, content);
                    },
                    dataPointMouseLeave() {
                        controller._activeHeatmapTooltipContent = null;
                        controller.hideHeatmapTooltip();
                    },
                    mouseMove(event) {
                        if (controller._activeHeatmapTooltipContent) {
                            controller.showHeatmapTooltip(
                                event,
                                controller._activeHeatmapTooltipContent,
                            );
                        }
                    },
                },
            },
            colors: ['#2fb344'],
            plotOptions: {
                heatmap: {
                    radius: 2,
                    enableShades: false,
                    colorScale: {
                        min: colorScale.min,
                        max: colorScale.max,
                        ranges: colorScale.ranges,
                    },
                },
            },
            dataLabels: { enabled: false },
            legend: { show: false },
            tooltip: { enabled: false },
            series,
            xaxis: { type: 'category' },
        });

        chart.render();
        this.heatmapInstance = chart;
    }

    renderGroupedBar(ApexCharts, element, categories, series, options, generation) {
        if (generation !== this._renderGeneration) {
            return;
        }

        const horizontal = options.horizontal ?? false;
        const percentFormatter = (value) => `${Math.round(value * 10) / 10}%`;

        const chart = new ApexCharts(element, {
            chart: {
                type: 'bar',
                height: options.height ?? 280,
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            plotOptions: {
                bar: {
                    horizontal,
                    columnWidth: '55%',
                    barHeight: horizontal ? (options.barHeight ?? '70%') : undefined,
                    borderRadius: horizontal ? 3 : undefined,
                },
            },
            dataLabels: { enabled: false },
            stroke: { show: true, width: 1, colors: ['transparent'] },
            series,
            xaxis: {
                categories,
                labels: horizontal ? { formatter: percentFormatter } : { trim: true },
            },
            yaxis: {
                labels: horizontal ? { trim: true } : { formatter: percentFormatter },
            },
            tooltip: {
                y: {
                    formatter: percentFormatter,
                },
            },
            legend: { position: 'top' },
        });

        this.instances.push(chart);
        void chart.render();
    }
}
