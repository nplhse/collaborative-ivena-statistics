/**
 * Builds ApexCharts options from the analysis chart spec payload.
 *
 * @param {Record<string, unknown>} data
 * @returns {import('apexcharts').ApexOptions | null}
 */
export function buildAnalysisChartOptions(data) {
    if (!data || typeof data !== 'object') {
        return null;
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
        const counts = Array.isArray(data.counts) ? data.counts : [];
        series = [
            {
                name: 'Allocations',
                data: coerceNumbers(counts),
            },
        ];
    }

    const multi = series.length > 1;
    const percentScale = data.percentScale === true;
    const barGrouped = data.barGrouped === true && !percentScale;

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
        !isLine && multi && chartType === 'bar' && (percentScale || !barGrouped);

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
            height: 320,
            toolbar: { show: false },
            fontFamily: 'inherit',
            zoom: { enabled: false },
            stacked: stackedBars,
        },
        series,
        xaxis: {
            categories: labels,
            labels: {
                rotate: !horizontal && labels.length > 8 ? -45 : 0,
                trim: true,
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
            show: percentScale,
            labels: {
                formatter: percentScale ? formatPercentValue : undefined,
            },
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
