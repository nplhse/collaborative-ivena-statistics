import { buildAnalysisHeatmapOptions } from './build-analysis-heatmap-options.js';
import { ANALYSIS_CHART_HEIGHT_PROFILE_EXPLORER } from './analysis-chart-height-profile.js';

/**
 * Tabler default sans stack — used when ApexCharts cannot resolve `inherit` (e.g. PNG export).
 */
export const ANALYSIS_CHART_FONT_FAMILY =
    "Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

/**
 * @returns {string}
 */
export function resolveAnalysisChartFontFamily() {
    if (typeof document !== 'undefined' && document.body) {
        const bodyFont = getComputedStyle(document.body).fontFamily;
        if (bodyFont && bodyFont !== 'inherit') {
            return bodyFont;
        }
    }

    return ANALYSIS_CHART_FONT_FAMILY;
}

/**
 * @param {unknown} text
 * @param {{ offsetX?: number, offsetY?: number }} offsets
 */
function buildAxisTitleConfig(text, offsets = {}) {
    if (typeof text !== 'string' || text.trim() === '') {
        return undefined;
    }

    return {
        text,
        offsetX: offsets.offsetX ?? 0,
        offsetY: offsets.offsetY ?? 0,
        style: {
            fontFamily: resolveAnalysisChartFontFamily(),
            fontSize: '12px',
            fontWeight: 500,
            color: '#495057',
        },
    };
}

export { ANALYSIS_CHART_HEIGHT_PROFILE_EXPLORER } from './analysis-chart-height-profile.js';

/**
 * @param {boolean} isMulti
 * @param {number} seriesCount
 * @param {string} [profile]
 */
export function getAnalysisChartHeight(isMulti, seriesCount, profile = '') {
    if (profile === ANALYSIS_CHART_HEIGHT_PROFILE_EXPLORER) {
        if (isMulti && seriesCount > 6) {
            return 500;
        }

        if (isMulti) {
            return 460;
        }

        return 440;
    }

    if (isMulti && seriesCount > 6) {
        return 420;
    }

    if (isMulti) {
        return 380;
    }

    return 360;
}

/**
 * @param {{
 *   isMulti: boolean,
 *   seriesCount: number,
 *   horizontal?: boolean,
 *   labelCount?: number,
 *   profile?: string,
 * }} params
 */
export function resolveAnalysisChartHeight({
    isMulti,
    seriesCount,
    horizontal = false,
    labelCount = 0,
    profile = '',
}) {
    let height = getAnalysisChartHeight(isMulti, seriesCount, profile);

    if (profile === ANALYSIS_CHART_HEIGHT_PROFILE_EXPLORER && horizontal && labelCount > 0) {
        height = Math.min(680, Math.max(height, labelCount * 28 + 120));
    }

    return height;
}

/**
 * @param {Record<string, unknown>} [buildOptions]
 * @returns {string}
 */
function resolveChartHeightProfile(buildOptions) {
    const profile = buildOptions?.chartHeightProfile;
    return typeof profile === 'string' ? profile : '';
}

/**
 * Builds ApexCharts options from the analysis chart spec payload.
 *
 * @param {Record<string, unknown>} data
 * @param {Record<string, unknown>} [buildOptions]
 * @returns {import('apexcharts').ApexOptions | null}
 */
export function buildAnalysisChartOptions(data, buildOptions = {}) {
    if (!data || typeof data !== 'object') {
        return null;
    }

    const chartHeightProfile = resolveChartHeightProfile(buildOptions);

    if (data.chartType === 'heatmap') {
        return buildAnalysisHeatmapOptions(data, { chartHeightProfile });
    }

    const labels = Array.isArray(data.labels) ? data.labels : [];
    if (labels.length === 0) {
        return null;
    }

    const chartType = data.chartType === 'line' ? 'line' : 'bar';
    const isLine = chartType === 'line';
    const horizontal = data.horizontal === true;

    const coerceNumbers = (arr) => {
        if (!Array.isArray(arr)) {
            return [];
        }
        return arr.map((v) => {
            const n = typeof v === 'number' ? v : Number(v);
            return Number.isFinite(n) ? n : 0;
        });
    };

    /** @type {{ name: string, data: number[] }[]} */
    let series;
    if (Array.isArray(data.series) && data.series.length > 0) {
        series = data.series.map((s) => ({
            name: (s && typeof s.name === 'string' ? s.name : '') || '',
            data: coerceNumbers(s && s.data),
        }));
    } else {
        const values = Array.isArray(data.values)
            ? data.values
            : Array.isArray(data.counts)
              ? data.counts
              : [];
        const valueLabel =
            typeof data.valueLabel === 'string' && data.valueLabel !== ''
                ? data.valueLabel
                : 'Allocations';
        series = [
            {
                name: valueLabel,
                data: coerceNumbers(values),
            },
        ];
    }

    const multi = series.length > 1;
    const percentScale = data.percentScale === true || data.valueFormat === 'percent';
    const barGrouped = data.barGrouped === true && !percentScale;
    const categoryAxisTitle =
        typeof data.xAxisLabel === 'string' && data.xAxisLabel !== '' ? data.xAxisLabel : undefined;
    const valueAxisTitle =
        typeof data.yAxisLabel === 'string' && data.yAxisLabel !== ''
            ? data.yAxisLabel
            : typeof data.valueLabel === 'string' && data.valueLabel !== ''
              ? data.valueLabel
              : undefined;
    const xAxisTitle = horizontal
        ? buildAxisTitleConfig(valueAxisTitle, { offsetY: 4 })
        : buildAxisTitleConfig(categoryAxisTitle, { offsetY: 6 });
    const yAxisTitle = horizontal
        ? buildAxisTitleConfig(categoryAxisTitle, { offsetX: 0 })
        : buildAxisTitleConfig(valueAxisTitle, { offsetX: 0 });
    const chartHeight = resolveAnalysisChartHeight({
        isMulti: multi,
        seriesCount: series.length,
        horizontal,
        labelCount: labels.length,
        profile: chartHeightProfile,
    });
    const legendBottomSpace = multi ? (series.length > 6 ? 88 : 44) : 0;
    const categoryTitleSpace = !horizontal && categoryAxisTitle ? 22 : 0;
    const valueTitleSpace = !horizontal && valueAxisTitle ? 24 : 0;
    const chartFontFamily = resolveAnalysisChartFontFamily();

    const formatPercentValue = (val) => {
        const n = typeof val === 'number' ? val : Number(val);
        if (!Number.isFinite(n)) {
            return '—';
        }
        const rounded = Math.round(n * 100) / 100;
        const str =
            rounded % 1 === 0
                ? String(rounded)
                : rounded.toLocaleString(undefined, {
                      minimumFractionDigits: 0,
                      maximumFractionDigits: 2,
                  });
        return `${str}%`;
    };

    const stackedBars =
        !isLine &&
        multi &&
        chartType === 'bar' &&
        (data.stacked === true || percentScale || !barGrouped);

    let maxVal = 0;
    if (stackedBars) {
        const n = labels.length;
        for (let i = 0; i < n; i++) {
            let sum = 0;
            for (const s of series) {
                sum += s.data[i] ?? 0;
            }
            if (sum > maxVal) {
                maxVal = sum;
            }
        }
    } else {
        for (const s of series) {
            for (const v of s.data) {
                if (v > maxVal) {
                    maxVal = v;
                }
            }
        }
    }

    const barPlotOptions =
        chartType === 'bar'
            ? {
                  bar: {
                      horizontal,
                      columnWidth: percentScale && stackedBars ? '65%' : '55%',
                      ...(stackedBars
                          ? percentScale
                              ? {
                                    borderRadius: 0,
                                    borderRadiusApplication: 'end',
                                }
                              : {
                                    borderRadius: 2,
                                    borderRadiusApplication: 'end',
                                    borderRadiusWhenStacked: 'last',
                                }
                          : {
                                borderRadius: 4,
                                borderRadiusApplication: 'end',
                            }),
                  },
              }
            : {};

    return {
        chart: {
            type: chartType,
            height: chartHeight,
            toolbar: { show: false },
            fontFamily: chartFontFamily,
            zoom: { enabled: false },
            stacked: stackedBars,
            animations: {
                enabled: true,
            },
        },
        title: {
            show: false,
            text: undefined,
        },
        series,
        xaxis: {
            categories: labels,
            title: xAxisTitle,
            labels: {
                show: true,
                rotate: !horizontal && labels.length > 8 ? -45 : 0,
                trim: true,
                style: {
                    fontFamily: chartFontFamily,
                },
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            min: 0,
            max:
                maxVal === 0
                    ? undefined
                    : percentScale
                      ? maxVal <= 100
                          ? 100
                          : Math.ceil(maxVal * 1.05)
                      : Math.ceil(maxVal * 1.1),
            show: true,
            title: yAxisTitle,
            labels: {
                show: true,
                formatter: percentScale ? formatPercentValue : undefined,
                style: {
                    fontFamily: chartFontFamily,
                },
            },
            axisBorder: { show: false },
            axisTicks: { show: true },
        },
        plotOptions: barPlotOptions,
        stroke: isLine
            ? {
                  curve: 'smooth',
                  width: 3,
                  lineCap: 'round',
              }
            : percentScale && stackedBars
              ? {
                    show: true,
                    width: 1,
                    colors: ['#fff'],
                }
              : {},
        ...(multi
            ? {
                  colors: [
                      '#206bc4',
                      '#d63939',
                      '#74b816',
                      '#fab005',
                      '#ae3ec9',
                      '#15aabf',
                      '#fd7e14',
                      '#7048e8',
                  ].slice(0, series.length),
              }
            : isLine && !multi
              ? {
                    colors: ['#206bc4'],
                }
              : {}),
        dataLabels: { enabled: false },
        grid: {
            strokeDashArray: 4,
            borderColor: 'rgba(0,0,0,0.04)',
            padding: {
                top: 8,
                right: 12,
                bottom: 8 + legendBottomSpace + categoryTitleSpace,
                left: 12 + valueTitleSpace,
            },
        },
        tooltip: {
            shared: true,
            intersect: false,
            ...(percentScale
                ? {
                      y: {
                          formatter: formatPercentValue,
                      },
                  }
                : {}),
        },
        legend: {
            show: multi,
            position: 'bottom',
            fontSize: '12px',
            fontFamily: chartFontFamily,
            markers: { width: 8, height: 8, radius: 2 },
            ...(multi && series.length > 6
                ? {
                      height: 96,
                      offsetY: 4,
                  }
                : {}),
        },
    };
}

/**
 * @param {Record<string, unknown>} data
 * @param {string} chartTitle
 * @param {Record<string, unknown>} [buildOptions]
 * @returns {import('apexcharts').ApexOptions | null}
 */
export function buildAnalysisChartExportOptions(data, chartTitle, buildOptions = {}) {
    const options = buildAnalysisChartOptions(data, buildOptions);
    if (!options) {
        return null;
    }

    const trimmedTitle = chartTitle.trim();
    const hasTitle = trimmedTitle !== '';
    const baseHeight =
        typeof options.chart?.height === 'number'
            ? options.chart.height
            : getAnalysisChartHeight(false, 1, resolveChartHeightProfile(buildOptions));
    const exportHeight = baseHeight + (hasTitle ? 52 : 0);
    const basePadding = options.grid?.padding ?? {};
    const exportLeftPadding = Math.max(basePadding.left ?? 12, 36);

    return {
        ...options,
        chart: {
            ...options.chart,
            height: exportHeight,
            animations: {
                enabled: false,
            },
        },
        grid: {
            ...options.grid,
            padding: {
                ...basePadding,
                left: exportLeftPadding,
                ...(hasTitle
                    ? {
                          top: (basePadding.top ?? 8) + 8,
                      }
                    : {}),
            },
        },
        ...(hasTitle
            ? {
                  title: {
                      show: true,
                      text: trimmedTitle,
                      align: 'center',
                      margin: 18,
                      style: {
                          fontFamily: resolveAnalysisChartFontFamily(),
                          fontSize: '16px',
                          fontWeight: 600,
                          color: '#1e293b',
                      },
                  },
              }
            : {}),
    };
}
