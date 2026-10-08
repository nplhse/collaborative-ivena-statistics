export function formatClosureChartCount(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return '';
    }

    return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 1 }).format(number);
}

export const BAR_SERIES_KEYS = ['sk1', 'sk2', 'sk3', 'resus', 'cathlab'];
export const BAR_SERIES_META = {
    sk1: { name: 'SK1', color: '#e03131' },
    sk2: { name: 'SK2', color: '#fab005' },
    sk3: { name: 'SK3', color: '#2f9e44' },
    resus: { name: 'Schockraum', color: '#f76707' },
    cathlab: { name: 'Herzkatheter', color: '#7950f2' },
};

export const CLOSURE_ASSIGNMENTS_CHART_LEGEND = {
    position: 'top',
    horizontalAlign: 'right',
    clusterGroupedSeries: false,
    itemMargin: { horizontal: 10, vertical: 0 },
    onItemClick: { toggleDataSeries: true },
};

const CLOSURE_PALETTE_FALLBACK = {
    currentFill: 'rgba(32, 107, 196, 0.22)',
    currentBorder: '#1864ab',
    otherFill: 'rgba(173, 181, 189, 0.14)',
    otherBorder: 'rgba(134, 142, 150, 0.55)',
};

export function closureChartPalette(chartRoot) {
    const host =
        chartRoot?.closest?.('[data-controller~="closure-analytics-charts"]') ?? chartRoot ?? null;
    if (!host || typeof getComputedStyle !== 'function') {
        return { ...CLOSURE_PALETTE_FALLBACK };
    }
    const style = getComputedStyle(host);

    return {
        currentFill:
            style.getPropertyValue('--closure-chart-current-fill').trim() ||
            CLOSURE_PALETTE_FALLBACK.currentFill,
        currentBorder:
            style.getPropertyValue('--closure-chart-current-border').trim() ||
            CLOSURE_PALETTE_FALLBACK.currentBorder,
        otherFill:
            style.getPropertyValue('--closure-chart-other-fill').trim() ||
            CLOSURE_PALETTE_FALLBACK.otherFill,
        otherBorder:
            style.getPropertyValue('--closure-chart-other-border').trim() ||
            CLOSURE_PALETTE_FALLBACK.otherBorder,
    };
}

export function barSeriesEntries(barSeries) {
    return BAR_SERIES_KEYS.map((key) => ({
        key,
        meta: BAR_SERIES_META[key],
        data: barSeries[key] ?? [],
    })).filter((entry) => entry.data.length > 0);
}

export function placeClosureBandsBehind(root) {
    const inner = root?.querySelector('.apexcharts-inner');
    const series = inner?.querySelector(':scope > .apexcharts-plot-series');
    if (!inner || !series) {
        return;
    }
    root.querySelectorAll('.apexcharts-annotation-rect').forEach((rect) => {
        inner.insertBefore(rect, series);
    });
}

export function buildClosureXAxisAnnotations(categories, chartData, otherClosures, chartRoot) {
    const palette = closureChartPalette(chartRoot);
    const starts = chartData.starts ?? [];
    const xaxis = (chartData.closureBands ?? []).map(([from, to]) => ({
        x: categories[from],
        x2: categories[to],
        fillColor: palette.currentFill,
        opacity: 1,
        borderColor: palette.currentBorder,
        strokeDashArray: 0,
    }));
    const otherClosureAt = categories.map(() => null);
    otherClosures.forEach((closure) => {
        const from = starts.findIndex((start) => start >= closure.start && start < closure.end);
        if (from < 0) {
            return;
        }
        let to = from;
        while (to + 1 < starts.length && starts[to + 1] < closure.end) {
            to += 1;
        }
        for (let index = from; index <= to; index += 1) {
            otherClosureAt[index] = closure.label;
        }
        xaxis.push({
            x: categories[from],
            x2: categories[to],
            fillColor: palette.otherFill,
            opacity: 1,
            borderColor: palette.otherBorder,
            strokeDashArray: 4,
        });
    });
    const anchorCategory = chartData.anchorCategory ?? null;
    if (anchorCategory) {
        xaxis.push({
            x: anchorCategory,
            x2: anchorCategory,
            borderColor: palette.currentBorder,
            strokeDashArray: 4,
        });
    }

    return { xaxis, otherClosureAt };
}

function seriesDataMax(series) {
    let max = 0;
    for (const item of series) {
        for (const value of item.data ?? []) {
            const number = Number(value);
            if (Number.isFinite(number) && number > max) {
                max = number;
            }
        }
    }

    return max;
}

function yAxisMaxWithHeadroom(dataMax) {
    if (!Number.isFinite(dataMax) || dataMax <= 0) {
        return undefined;
    }
    const padded = dataMax * 1.2;
    if (padded <= 6) {
        return Math.ceil(padded * 2) / 2;
    }
    if (padded <= 24) {
        return Math.ceil(padded);
    }

    return Math.ceil(padded / 5) * 5;
}

export function assignmentsYAxis(series, title) {
    const max = yAxisMaxWithHeadroom(seriesDataMax(series));

    return {
        min: 0,
        max,
        forceNiceScale: max === undefined,
        decimalsInFloat: 1,
        title: { text: title },
        labels: { formatter: (value) => formatClosureChartCount(value) },
    };
}

function countYAxis(series, title, decimalsInFloat = 1) {
    const max = yAxisMaxWithHeadroom(seriesDataMax(series));

    return {
        min: 0,
        max,
        forceNiceScale: max === undefined,
        decimalsInFloat,
        title: { text: title },
        labels: { formatter: (value) => formatClosureChartCount(value) },
    };
}

function yAxisSeriesNames(names) {
    if (names.length === 0) {
        return undefined;
    }
    if (names.length === 1) {
        return names[0];
    }

    return names;
}

export function mixedAssignmentYAxes(lineSeries, barEntries, lineAxisLabel, barAxisLabel) {
    const barSeries = barEntries.map((entry) => ({ data: entry.data }));
    const lineNames = lineSeries.map((entry) => entry.name);
    const barNames = barEntries.map((entry) => entry.meta.name);

    return [
        {
            ...countYAxis(lineSeries, lineAxisLabel, 2),
            seriesName: yAxisSeriesNames(lineNames),
        },
        {
            ...countYAxis(barSeries, barAxisLabel, 1),
            opposite: true,
            show: barEntries.length > 0,
            seriesName: yAxisSeriesNames(barNames),
        },
    ];
}

export function volumeAssignmentLineSeries(volume) {
    return [
        { name: volume.observedLabel ?? 'Beobachtet', type: 'line', data: volume.observed ?? [] },
        { name: volume.expectedLabel ?? 'Erwartet', type: 'line', data: volume.expected ?? [] },
    ];
}

export function profileAssignmentLineSeries(course) {
    return [
        { name: course.observedLabel ?? 'Beobachtet', type: 'line', data: course.observed ?? [] },
        { name: course.expectedLabel ?? 'Erwartet', type: 'line', data: course.expected ?? [] },
        { name: course.meanLabel ?? 'Mittelwert', type: 'line', data: course.mean ?? [] },
    ];
}

export function volumeAssignmentChartColors(barEntries) {
    return ['#1864ab', '#74c0fc', ...barEntries.map((entry) => entry.meta.color)];
}

export function profileAssignmentChartColors(barEntries) {
    return ['#1864ab', '#74c0fc', '#868e96', ...barEntries.map((entry) => entry.meta.color)];
}

export function assignmentStrokeWidth(lineCount, index) {
    if (index >= lineCount) {
        return 0;
    }
    if (lineCount === 2) {
        return index === 0 ? 3 : 2;
    }

    return [3, 2, 1][index] ?? 0;
}

export function assignmentStrokeDash(lineCount, index) {
    if (index >= lineCount) {
        return 0;
    }
    if (lineCount === 2) {
        return index === 1 ? 6 : 0;
    }

    return index === 1 ? 6 : index === 2 ? 2 : 0;
}

export function buildAssignmentMixedSeries(lineSeries, barSeries) {
    const barEntries = barSeriesEntries(barSeries);
    const series = [
        ...lineSeries,
        ...barEntries.map((entry) => ({
            name: entry.meta.name,
            type: 'column',
            data: entry.data,
            hidden: true,
        })),
    ];

    return { series, barEntries };
}

export function buildAssignmentMixedChartOptions({
    categories,
    lineSeries,
    barSeries,
    chartData,
    otherClosures,
    chartRoot,
    height,
    colorProfile = 'volume',
    tooltip,
}) {
    const { series, barEntries } = buildAssignmentMixedSeries(lineSeries, barSeries);
    const lineCount = lineSeries.length;
    const { xaxis: closureAnnotations, otherClosureAt } = buildClosureXAxisAnnotations(
        categories,
        chartData,
        otherClosures,
        chartRoot,
    );
    const assignmentsAxisLabel =
        chartData.assignmentsAxisLabel ?? chartData.countAxisLabel ?? 'Zuweisungen';
    const lineAxisLabel =
        colorProfile === 'profile'
            ? (chartData.rateAxisLabel ?? chartData.assignmentsAxisLabel ?? 'Rate')
            : assignmentsAxisLabel;
    const barAxisLabel = chartData.barCountAxisLabel ?? assignmentsAxisLabel;
    const colors =
        colorProfile === 'profile'
            ? profileAssignmentChartColors(barEntries)
            : volumeAssignmentChartColors(barEntries);

    return {
        otherClosureAt,
        barEntries,
        options: {
            chart: {
                type: 'line',
                height,
                stacked: false,
                toolbar: { show: false },
                fontFamily: 'inherit',
                events: {
                    mounted: () => placeClosureBandsBehind(chartRoot),
                    updated: () => placeClosureBandsBehind(chartRoot),
                },
            },
            series,
            colors,
            stroke: {
                width: series.map((_entry, index) => assignmentStrokeWidth(lineCount, index)),
                curve: 'smooth',
                dashArray: series.map((_entry, index) => assignmentStrokeDash(lineCount, index)),
            },
            markers: { size: 0, hover: { size: 4 } },
            plotOptions: { bar: { columnWidth: '55%', borderRadius: 2 } },
            grid: { padding: { top: 12, right: 8, left: 4, bottom: 0 } },
            xaxis: { categories, labels: { rotate: -45, hideOverlappingLabels: true } },
            yaxis: mixedAssignmentYAxes(lineSeries, barEntries, lineAxisLabel, barAxisLabel),
            annotations: { xaxis: closureAnnotations },
            legend: CLOSURE_ASSIGNMENTS_CHART_LEGEND,
            tooltip: {
                shared: true,
                intersect: false,
                custom: tooltip,
            },
        },
    };
}

function hasVolumeSpecialityRatioReference(volume) {
    return Array.isArray(volume.referenceAreaRatio) && volume.referenceAreaRatio.length > 0;
}

export function volumeRatioSeries(volume) {
    const series = [
        {
            name: volume.areaRatioLabel ?? 'Betroffener Bereich',
            data: volume.areaRatio ?? [],
        },
        {
            name: volume.hospitalRatioLabel ?? 'Gesamte Klinik',
            data: volume.hospitalRatio ?? [],
        },
    ];
    if (!hasVolumeSpecialityRatioReference(volume)) {
        return series;
    }

    return [
        ...series,
        {
            name: volume.referenceAreaRatioLabel ?? 'Fachgebiet',
            data: volume.referenceAreaRatio ?? [],
        },
    ];
}

export function volumeRatioColors(volume) {
    const colors = ['#206bc4', '#9aa5b1'];
    if (hasVolumeSpecialityRatioReference(volume)) {
        colors.push('#868e96');
    }

    return colors;
}

export function volumeRatioStrokeWidths(volume) {
    const count = volumeRatioSeries(volume).length;

    return Array.from({ length: count }, (_value, index) =>
        index === count - 1 && count > 2 ? 2 : 3,
    );
}

export function volumeRatioStrokeDash(volume) {
    const count = volumeRatioSeries(volume).length;
    if (count <= 2) {
        return Array.from({ length: count }, () => 0);
    }

    return [0, 0, 6];
}

export function buildVolumeRatioChartOptions(
    categories,
    volume,
    otherClosures,
    chartRoot,
    tooltip,
) {
    const { xaxis: closureAnnotations, otherClosureAt } = buildClosureXAxisAnnotations(
        categories,
        volume,
        otherClosures,
        chartRoot,
    );

    return {
        options: {
            chart: {
                type: 'line',
                height: 260,
                toolbar: { show: false },
                fontFamily: 'inherit',
                events: {
                    mounted: () => placeClosureBandsBehind(chartRoot),
                    updated: () => placeClosureBandsBehind(chartRoot),
                },
            },
            series: volumeRatioSeries(volume),
            colors: volumeRatioColors(volume),
            stroke: {
                width: volumeRatioStrokeWidths(volume),
                curve: 'smooth',
                dashArray: volumeRatioStrokeDash(volume),
            },
            grid: { padding: { top: 12, right: 8, left: 4, bottom: 0 } },
            xaxis: { categories, labels: { rotate: -45, hideOverlappingLabels: true } },
            yaxis: {
                decimalsInFloat: 0,
                labels: { formatter: (value) => `${Math.round(Number(value))} %` },
            },
            annotations: {
                xaxis: closureAnnotations,
                yaxis: [{ y: 100, borderColor: '#868e96', strokeDashArray: 4 }],
            },
            legend: CLOSURE_ASSIGNMENTS_CHART_LEGEND,
            tooltip: {
                shared: true,
                intersect: false,
                custom: tooltip,
            },
        },
        otherClosureAt,
    };
}

export function volumeAssignmentTooltip(w, dataPointIndex, volume) {
    const category = w.globals.categoryLabels[dataPointIndex] ?? '';
    const rows = w.config.series
        .map((series) => {
            const value = series.data?.[dataPointIndex];
            if (value === null || value === undefined) {
                return '';
            }
            return `<div>${series.name}: ${formatClosureChartCount(value)}</div>`;
        })
        .filter(Boolean)
        .join('');
    const influenced = volume.influenced?.[dataPointIndex]
        ? `<div>${volume.influencedLabel ?? 'Einfluss'}</div>`
        : '';
    const otherClosure = volume.otherClosureAt?.[dataPointIndex]
        ? `<div>${volume.otherClosureHint ?? 'Andere Schließung'}: ${volume.otherClosureAt[dataPointIndex]}</div>`
        : '';

    return `<div class="p-2"><strong>${category}</strong>${otherClosure}${rows}${influenced}</div>`;
}
