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
    gapBorder: '#868e96',
    gapFill: 'rgba(134, 142, 150, 0.28)',
    markerText: '#fff',
};

const NARROW_CHART_WIDTH = 576;

const CLOSURE_CHART_RESPONSIVE_LEGEND = {
    position: 'bottom',
    horizontalAlign: 'left',
};

export function isNarrowChart(chartRoot) {
    const width = chartRoot?.clientWidth ?? 0;

    return width > 0 && width < NARROW_CHART_WIDTH;
}

/**
 * The course charts use a slot axis: slot i covers [i, i + 1), its value sits in the middle, and the
 * markers for start, end, bands and the hidden gap sit exactly on the slot boundaries.
 */
export function slotData(values) {
    return (values ?? []).map((value, index) => [index + 0.5, value ?? null]);
}

function formatOffset(offset) {
    if (offset === 0) {
        return '0 h';
    }

    return `${offset > 0 ? '+' : '−'}${Math.abs(offset)} h`;
}

function formatClock(value, previous) {
    const time = value.substring(11, 16);
    if (previous !== null && previous.substring(0, 10) === value.substring(0, 10)) {
        return time;
    }

    return `${value.substring(8, 10)}.${value.substring(5, 7)}. ${time}`;
}

/**
 * Raw values of the slot boundaries 0 … n: hour offsets for profile courses, wall-clock
 * timestamps for event and interval courses.
 */
function slotBoundaries(chartData) {
    const points = chartData.points ?? [];
    if (points.length > 0 && Number.isFinite(Number(points[0].offset))) {
        const offsets = points.map((point) => Number(point.offset));
        offsets.push(offsets[offsets.length - 1] + 1);

        return { kind: 'offset', values: offsets };
    }
    const starts = chartData.starts ?? [];
    const ends = chartData.ends ?? [];
    if (starts.length === 0) {
        return { kind: 'clock', values: [] };
    }

    return {
        kind: 'clock',
        values: [...starts, ends[ends.length - 1] ?? starts[starts.length - 1]],
    };
}

/**
 * Labels for the slot boundaries; boundaries not in `shown` stay empty. A clock label repeats the
 * date only where it differs from the previous shown label.
 */
function formatSlotBoundaries({ kind, values }, shown = null) {
    let previous = null;

    return values.map((value, index) => {
        if (shown !== null && !shown[index]) {
            return '';
        }
        if (kind === 'offset') {
            return formatOffset(value);
        }
        if (typeof value !== 'string' || value.length < 16) {
            return '';
        }
        const label = formatClock(value, previous);
        previous = value;

        return label;
    });
}

export function slotBoundaryLabels(chartData) {
    return formatSlotBoundaries(slotBoundaries(chartData));
}

/**
 * Boundaries that always keep their label: both axis ends, closure start and end, and hour 0 of a
 * profile course.
 */
function keySlotBoundaries(chartData, count) {
    const keys = [0, count];
    if (Number.isInteger(chartData.closureStartIndex)) {
        keys.push(chartData.closureStartIndex);
    }
    if (Number.isInteger(chartData.closureEndIndex)) {
        keys.push(chartData.closureEndIndex + 1);
    }
    const anchor = anchorSlot(chartData);
    if (anchor !== null) {
        keys.push(anchor);
    }

    return keys;
}

/**
 * On narrow charts only every n-th boundary is labelled; the key boundaries always are.
 */
export function visibleSlotLabels(chartData, narrow) {
    const boundaries = slotBoundaries(chartData);
    const count = boundaries.values.length - 1;
    const step = narrow ? Math.ceil(count / 6) : 1;
    if (step <= 1) {
        return formatSlotBoundaries(boundaries);
    }
    const keys = keySlotBoundaries(chartData, count);
    const shown = boundaries.values.map((_value, index) => {
        if (keys.includes(index)) {
            return true;
        }

        return index % step === 0 && !keys.some((key) => Math.abs(key - index) < step);
    });

    return formatSlotBoundaries(boundaries, shown);
}

export function slotXAxis(chartData, title = null, chartRoot = null) {
    const labels = visibleSlotLabels(chartData, isNarrowChart(chartRoot));
    const count = Math.max(labels.length - 1, 1);

    return {
        type: 'numeric',
        min: 0,
        max: count,
        tickAmount: count,
        forceNiceScale: false,
        decimalsInFloat: 0,
        tooltip: { enabled: false },
        labels: {
            rotate: -45,
            rotateAlways: count > 12,
            hideOverlappingLabels: true,
            formatter: (value) => labels[Math.round(Number(value))] ?? '',
        },
        ...(title ? { title: { text: title } } : {}),
    };
}

/**
 * Tooltip heading: the slot from its start boundary to its end boundary, or the gap label.
 */
export function closureBucketLabel(chartData, index) {
    if (index === chartData.gapIndex) {
        return chartData.gapLabel ?? '';
    }
    const labels = slotBoundaryLabels(chartData);
    const from = labels[index] ?? '';
    const until = labels[index + 1] ?? '';
    if (until === '') {
        return from;
    }
    // Repeat the date only when the slot crosses midnight.
    const untilTime = until.length > 5 && from.length > 5 ? until.slice(-5) : until;

    return `${from} – ${untilTime}`;
}

function markerLabel(text, background, offsetY = 0, color = '#fff') {
    return {
        text,
        orientation: 'horizontal',
        position: 'top',
        offsetY,
        borderColor: background,
        style: {
            color,
            background,
            fontSize: '11px',
            fontWeight: 600,
            padding: { left: 4, right: 4, top: 1, bottom: 2 },
        },
    };
}

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
        gapBorder:
            style.getPropertyValue('--closure-chart-gap-border').trim() ||
            CLOSURE_PALETTE_FALLBACK.gapBorder,
        gapFill:
            style.getPropertyValue('--closure-chart-gap-fill').trim() ||
            CLOSURE_PALETTE_FALLBACK.gapFill,
        markerText:
            style.getPropertyValue('--closure-chart-marker-text').trim() ||
            CLOSURE_PALETTE_FALLBACK.markerText,
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

function anchorSlot(chartData) {
    const anchor = chartData.anchorCategory ?? null;
    if (!anchor) {
        return null;
    }
    const index = (chartData.categories ?? []).indexOf(anchor);

    return index < 0 ? null : index;
}

export function buildClosureXAxisAnnotations(chartData, otherClosures, chartRoot) {
    const palette = closureChartPalette(chartRoot);
    const starts = chartData.starts ?? [];
    const xaxis = (chartData.closureBands ?? []).map(([from, to]) => ({
        x: from,
        x2: to + 1,
        fillColor: palette.currentFill,
        opacity: 1,
        borderColor: palette.currentBorder,
        strokeDashArray: 0,
    }));
    const otherClosureAt = starts.map(() => null);
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
            x: from,
            x2: to + 1,
            fillColor: palette.otherFill,
            opacity: 1,
            borderColor: palette.otherBorder,
            strokeDashArray: 4,
        });
    });
    xaxis.push(...closureMarkerAnnotations(chartData, chartRoot, palette));
    const anchor = anchorSlot(chartData);
    if (anchor !== null) {
        xaxis.push({
            x: anchor,
            borderColor: palette.currentBorder,
            strokeDashArray: 0,
            ...(chartData.anchorLabel
                ? {
                      label: markerLabel(
                          chartData.anchorLabel,
                          palette.currentBorder,
                          0,
                          palette.markerText,
                      ),
                  }
                : {}),
        });
    }

    return { xaxis, otherClosureAt };
}

/**
 * Lines on the exact closure start and end boundaries, and a band for the hidden middle of a long closure.
 */
export function closureMarkerAnnotations(chartData, chartRoot, palette) {
    const markers = [];
    const startIndex = chartData.closureStartIndex;
    const endIndex = chartData.closureEndIndex;
    if (Number.isInteger(startIndex) && chartData.startMarkerLabel) {
        markers.push({
            x: startIndex,
            borderColor: palette.currentBorder,
            strokeDashArray: 0,
            label: markerLabel(
                chartData.startMarkerLabel,
                palette.currentBorder,
                0,
                palette.markerText,
            ),
        });
    }
    const endText = chartData.ongoing ? chartData.ongoingMarkerLabel : chartData.endMarkerLabel;
    if (Number.isInteger(endIndex) && endText) {
        const crowded = Number.isInteger(startIndex) && endIndex + 1 - startIndex < 3;
        markers.push({
            x: endIndex + 1,
            borderColor: palette.currentBorder,
            strokeDashArray: chartData.ongoing ? 4 : 0,
            label: markerLabel(
                endText,
                palette.currentBorder,
                crowded ? 22 : 0,
                palette.markerText,
            ),
        });
    }
    const gapIndex = chartData.gapIndex;
    if (Number.isInteger(gapIndex) && chartData.gapLabel) {
        markers.push({
            x: gapIndex,
            x2: gapIndex + 1,
            fillColor: palette.gapFill,
            opacity: 1,
            borderColor: palette.gapBorder,
            strokeDashArray: 3,
            label: markerLabel(
                isNarrowChart(chartRoot)
                    ? (chartData.gapShortLabel ?? chartData.gapLabel)
                    : chartData.gapLabel,
                palette.gapBorder,
                22,
                palette.markerText,
            ),
        });
    }

    return markers;
}

function seriesDataMax(series) {
    let max = 0;
    for (const item of series) {
        for (const value of item.data ?? []) {
            const number = Number(Array.isArray(value) ? value[1] : value);
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
        ...lineSeries.map((entry) => ({ ...entry, data: slotData(entry.data) })),
        ...barEntries.map((entry) => ({
            name: entry.meta.name,
            type: 'column',
            data: slotData(entry.data),
            hidden: true,
        })),
    ];

    return { series, barEntries };
}

export function buildAssignmentMixedChartOptions({
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
            xaxis: slotXAxis(chartData, chartData.offsetAxisLabel ?? null, chartRoot),
            yaxis: mixedAssignmentYAxes(lineSeries, barEntries, lineAxisLabel, barAxisLabel),
            annotations: { xaxis: closureAnnotations },
            legend: CLOSURE_ASSIGNMENTS_CHART_LEGEND,
            responsive: [
                {
                    breakpoint: NARROW_CHART_WIDTH,
                    options: {
                        chart: { height: Math.round(height * 0.85) },
                        legend: CLOSURE_CHART_RESPONSIVE_LEGEND,
                    },
                },
            ],
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

/**
 * The axis always reaches past 100 % so the expectation line stays visible.
 */
export function ratioYAxisRange(series) {
    const dataMax = seriesDataMax(series);

    return { min: 0, max: Math.max(120, Math.ceil((dataMax * 1.1) / 20) * 20), tickAmount: 6 };
}

export function buildVolumeRatioChartOptions(volume, otherClosures, chartRoot, tooltip) {
    const { xaxis: closureAnnotations, otherClosureAt } = buildClosureXAxisAnnotations(
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
            series: volumeRatioSeries(volume).map((entry) => ({
                ...entry,
                data: slotData(entry.data),
            })),
            colors: volumeRatioColors(volume),
            stroke: {
                width: volumeRatioStrokeWidths(volume),
                curve: 'smooth',
                dashArray: volumeRatioStrokeDash(volume),
            },
            grid: { padding: { top: 12, right: 8, left: 4, bottom: 0 } },
            xaxis: slotXAxis(volume, null, chartRoot),
            yaxis: {
                ...ratioYAxisRange(volumeRatioSeries(volume)),
                decimalsInFloat: 0,
                ...(volume.ratioAxisLabel ? { title: { text: volume.ratioAxisLabel } } : {}),
                labels: { formatter: (value) => `${Math.round(Number(value))} %` },
            },
            annotations: {
                xaxis: closureAnnotations,
                yaxis: [
                    {
                        y: 100,
                        borderColor: '#868e96',
                        strokeDashArray: 4,
                        ...(volume.expectationLineLabel
                            ? {
                                  label: {
                                      text: volume.expectationLineLabel,
                                      position: 'left',
                                      textAnchor: 'start',
                                      borderColor: '#868e96',
                                      style: {
                                          color: '#fff',
                                          background: '#868e96',
                                          fontSize: '11px',
                                      },
                                  },
                              }
                            : {}),
                    },
                ],
            },
            legend: CLOSURE_ASSIGNMENTS_CHART_LEGEND,
            responsive: [
                {
                    breakpoint: NARROW_CHART_WIDTH,
                    options: { chart: { height: 230 }, legend: CLOSURE_CHART_RESPONSIVE_LEGEND },
                },
            ],
            tooltip: {
                shared: true,
                intersect: false,
                custom: tooltip,
            },
        },
        otherClosureAt,
    };
}

export function slotValue(point) {
    return Array.isArray(point) ? point[1] : point;
}

export function volumeAssignmentTooltip(w, dataPointIndex, volume) {
    const category = closureBucketLabel(volume, dataPointIndex);
    const rows = w.config.series
        .map((series) => {
            const value = slotValue(series.data?.[dataPointIndex]);
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
