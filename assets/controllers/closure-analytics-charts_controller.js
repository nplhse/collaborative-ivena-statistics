import { Controller } from '@hotwired/stimulus';
import { buildHeatmapColorScale } from '../lib/build-analysis-heatmap-options.js';
import {
    buildHeatmapSeries,
    heatmapColumnIndexFromSeriesIndex,
} from '../lib/build-heatmap-series.js';
import { formatChartMonthLabel } from '../lib/format-chart-month-label.js';
import { loadApexCharts } from '../lib/load-apexcharts.js';

const SHARE_COLORS = {
    none: '#9aa5b1',
    single: '#74c0fc',
    multiple: '#206bc4',
};

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { payload: Object };
    static targets = [
        'timeSeriesChart',
        'heatmapChart',
        'shareChart',
        'specialityChart',
        'reasonChart',
    ];

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
        const durationLoad = payload.durationLoad ?? {};
        if (this.hasShareChartTarget) {
            await this.renderShares(ApexCharts, durationLoad.shares ?? []);
        }
        if (this.hasSpecialityChartTarget) {
            await this.renderDistribution(
                ApexCharts,
                this.specialityChartTarget,
                durationLoad.specialities ?? [],
                durationLoad,
            );
        }
        if (this.hasReasonChartTarget) {
            await this.renderDistribution(
                ApexCharts,
                this.reasonChartTarget,
                durationLoad.reasons ?? [],
                durationLoad,
            );
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
        const matrix = payload.matrix ?? [];
        const colorScale = buildHeatmapColorScale(matrix);
        const chart = new ApexCharts(this.heatmapChartTarget, {
            chart: {
                type: 'heatmap',
                height: 420,
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: buildHeatmapSeries(labels, payload.rowLabels ?? [], matrix),
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

    async renderShares(ApexCharts, shares) {
        if (!Array.isArray(shares) || shares.length === 0) {
            return;
        }

        const chart = new ApexCharts(this.shareChartTarget, {
            chart: {
                type: 'bar',
                height: 160,
                stacked: true,
                stackType: '100%',
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: shares.map((share) => ({ name: share.name, data: [share.seconds] })),
            colors: shares.map((share) => SHARE_COLORS[share.key] ?? '#206bc4'),
            plotOptions: { bar: { horizontal: true, barHeight: '48%' } },
            xaxis: {
                categories: [''],
                labels: { show: false },
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            yaxis: { labels: { show: false } },
            legend: { position: 'bottom', horizontalAlign: 'left' },
            dataLabels: { enabled: false },
            tooltip: {
                y: {
                    formatter: (_value, opts) => {
                        const share = shares[opts.seriesIndex] ?? {};
                        return `${share.percentLabel ?? ''} · ${share.duration ?? ''}`;
                    },
                },
            },
        });
        this.charts.push(chart);
        await chart.render();
    }

    async renderDistribution(ApexCharts, element, groups, durationLoad) {
        if (!element || !Array.isArray(groups) || groups.length === 0) {
            return;
        }

        const axisLabel = durationLoad.axisLabel ?? '';
        const labels = durationLoad.tooltipLabels ?? {};
        const hasBox = groups.some((group) => group.mode === 'box' && Array.isArray(group.box));
        const options = hasBox
            ? this.boxPlotOptions(groups, durationLoad, 420, axisLabel, labels)
            : this.pointPlotOptions(
                  groups,
                  durationLoad,
                  Math.max(240, groups.length * 56 + 80),
                  axisLabel,
                  labels,
              );
        const chart = new ApexCharts(element, options);
        this.charts.push(chart);
        await chart.render();
    }

    boxPlotOptions(groups, durationLoad, height, axisLabel, labels) {
        const boxData = [];
        const pointColumns = [];
        const outlierColumns = [];
        groups.forEach((group, index) => {
            if (group.mode === 'box' && Array.isArray(group.box)) {
                const outliers = Array.isArray(group.outliers) ? group.outliers : [];
                boxData.push({ x: group.label, y: group.box, points: outliers });
                if (outliers.length > 0) {
                    outlierColumns.push(index);
                }
                return;
            }
            // Fewer than five observations stay points, but still need their own column.
            const values = Array.isArray(group.points) ? group.points : [];
            const anchor = values.length > 0 ? values[0] : 0;
            boxData.push({
                x: group.label,
                y: [anchor, anchor, anchor, anchor, anchor],
                points: values,
            });
            pointColumns.push(index);
        });
        const series = [{ name: durationLoad.boxName ?? '', type: 'boxPlot', data: boxData }];
        const colors = ['#206bc4'];
        let outlierSeriesIndex = -1;
        let pointSeriesIndex = -1;
        if (outlierColumns.length > 0) {
            outlierSeriesIndex = series.length;
            series.push({ name: durationLoad.outlierName ?? '', type: 'scatter', data: [] });
            colors.push('#d63939');
        }
        if (pointColumns.length > 0) {
            pointSeriesIndex = series.length;
            series.push({ name: durationLoad.pointName ?? '', type: 'scatter', data: [] });
            colors.push('#206bc4');
        }
        const paint = (chart) => {
            paintDurationPoints(
                chart,
                pointColumns,
                outlierColumns,
                outlierSeriesIndex,
                pointSeriesIndex,
            );
        };

        return {
            chart: {
                type: 'boxPlot',
                height,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
                events: {
                    beforeMount: paint,
                    mounted: paint,
                    updated: paint,
                    animationEnd: paint,
                },
            },
            series,
            colors,
            plotOptions: {
                boxPlot: {
                    colors: { upper: '#206bc4', lower: '#206bc4' },
                    points: {
                        show: true,
                        size: 4,
                        jitter: 0.35,
                        opacity: 1,
                        strokeWidth: 0,
                        fillColor: '#d63939',
                    },
                },
            },
            xaxis: {
                type: 'category',
                categories: groups.map((group) => group.label),
                labels: {
                    trim: true,
                    rotate: -45,
                    rotateAlways: true,
                    hideOverlappingLabels: false,
                    maxHeight: 140,
                    style: { fontFamily: 'inherit' },
                },
            },
            yaxis: durationValueAxis(groups, axisLabel),
            legend: { position: 'bottom', horizontalAlign: 'left' },
            dataLabels: { enabled: false },
            tooltip: {
                custom: ({ seriesIndex, dataPointIndex, w }) => {
                    const point = w.config.series[seriesIndex]?.data?.[dataPointIndex];
                    const label = typeof point?.x === 'string' ? point.x : point?.y;
                    return distributionTooltip(
                        groups.find((group) => group.label === label),
                        labels,
                    );
                },
            },
        };
    }

    pointPlotOptions(groups, durationLoad, height, axisLabel, labels) {
        return {
            chart: {
                type: 'scatter',
                height,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
            },
            series: [
                {
                    name: durationLoad.pointName ?? '',
                    data: groups.flatMap((group) =>
                        (group.points ?? []).map((value) => ({ x: value, y: group.label })),
                    ),
                },
            ],
            colors: ['#206bc4'],
            markers: { size: 7 },
            xaxis: {
                type: 'numeric',
                ...durationValueAxis(groups, axisLabel),
            },
            yaxis: {
                type: 'category',
                categories: groups.map((group) => group.label),
                labels: { maxWidth: 240, trim: true, style: { fontFamily: 'inherit' } },
            },
            legend: { show: false },
            tooltip: {
                custom: ({ seriesIndex, dataPointIndex, w }) => {
                    const point = w.config.series[seriesIndex]?.data?.[dataPointIndex];
                    return distributionTooltip(
                        groups.find((group) => group.label === point?.y),
                        labels,
                    );
                },
            },
        };
    }

    disconnect() {
        this.generation = (this.generation ?? 0) + 1;
        (this.charts ?? []).forEach((chart) => chart.destroy());
        this.charts = [];
    }
}

function paintDurationPoints(
    chart,
    pointColumns,
    outlierColumns,
    outlierSeriesIndex,
    pointSeriesIndex,
) {
    const root = chart?.el;
    if (!root) {
        return;
    }
    // A <style> element applies to the whole page. Two duration charts would
    // otherwise hide each other's boxes whenever a column index matches.
    const pointColumnSet = new Set(pointColumns);
    root.querySelectorAll('.apexcharts-boxPlot-area').forEach((element) => {
        if (!pointColumnSet.has(Number(element.getAttribute('j')))) {
            return;
        }
        element.style.stroke = 'transparent';
        element.style.fill = 'transparent';
    });
    root.querySelectorAll('.apexcharts-boxPlot-points').forEach((element) => {
        if (!pointColumnSet.has(Number(element.getAttribute('j')))) {
            return;
        }
        element.style.fill = '#206bc4';
        element.style.stroke = '#206bc4';
    });

    const collapsed = new Set(chart.w?.globals?.collapsedSeriesIndices ?? []);
    const hideOutliers = outlierSeriesIndex >= 0 && collapsed.has(outlierSeriesIndex);
    const hidePoints = pointSeriesIndex >= 0 && collapsed.has(pointSeriesIndex);
    root.querySelectorAll('.apexcharts-boxPlot-points').forEach((element) => {
        const index = Number(element.getAttribute('j'));
        const hidden =
            (hideOutliers && outlierColumns.includes(index)) ||
            (hidePoints && pointColumns.includes(index));
        element.style.display = hidden ? 'none' : '';
    });
}

const DURATION_AXIS_BANDS = [
    { limit: 60, step: 15 },
    { limit: 180, step: 30 },
    { limit: 360, step: 60 },
    { limit: 720, step: 120 },
    { limit: 1440, step: 240 },
];

function maxDurationMinutes(groups) {
    let max = 0;
    groups.forEach((group) => {
        [group.box, group.outliers, group.points].forEach((values) => {
            if (!Array.isArray(values)) {
                return;
            }
            values.forEach((value) => {
                const minutes = Number(value);
                if (Number.isFinite(minutes) && minutes > max) {
                    max = minutes;
                }
            });
        });
    });

    return max;
}

function durationAxisScale(observedMinutes) {
    const observed = Number.isFinite(observedMinutes) ? Math.max(0, observedMinutes) : 0;
    const band = DURATION_AXIS_BANDS.find((entry) => observed <= entry.limit);
    if (band) {
        return { max: band.limit, tickAmount: band.limit / band.step };
    }

    const step = 360;
    const max = Math.ceil(observed / step) * step;

    return { max, tickAmount: max / step };
}

function durationValueAxis(groups, title) {
    const scale = durationAxisScale(maxDurationMinutes(groups));

    return {
        min: 0,
        max: scale.max,
        tickAmount: scale.tickAmount,
        forceNiceScale: false,
        title: { text: title },
        labels: { formatter: (value) => formatAxisMinutes(value) },
    };
}

function formatAxisMinutes(value) {
    const minutes = Math.round(Number(value));
    if (!Number.isFinite(minutes)) {
        return '';
    }
    const absolute = Math.abs(minutes);
    const sign = minutes < 0 ? '-' : '';
    if (absolute < 60) {
        return `${sign}${absolute} min`;
    }
    if (absolute < 1440) {
        const hours = Math.floor(absolute / 60);
        const rest = absolute % 60;
        return rest > 0 ? `${sign}${hours} h ${rest} min` : `${sign}${hours} h`;
    }
    const days = Math.floor(absolute / 1440);
    const hours = Math.floor((absolute % 1440) / 60);
    return hours > 0 ? `${sign}${days} d ${hours} h` : `${sign}${days} d`;
}

function distributionTooltip(group, labels) {
    if (!group?.tooltip) {
        return '';
    }
    const rows = [
        [labels.count, group.tooltip.count],
        [labels.median, group.tooltip.median],
        [labels.q1, group.tooltip.q1],
        [labels.q3, group.tooltip.q3],
        [labels.minimum, group.tooltip.minimum],
        [labels.maximum, group.tooltip.maximum],
    ];
    const body = rows.map(([name, value]) => `${name}: ${value}`).join('<br>');

    return `<div class="p-2"><strong>${group.label}</strong><br>${body}</div>`;
}
