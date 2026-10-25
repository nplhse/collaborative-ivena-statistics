import { Controller } from '@hotwired/stimulus';
import { applyChartNonce, loadApexCharts } from '../lib/load-apexcharts.js';

const DAY_MS = 24 * 60 * 60 * 1000;
const UNITS = ['KB', 'MB', 'GB', 'TB'];

/**
 * Binary sizes like App\Storage\Application\ByteFormatter (1 KB = 1024 B).
 *
 * @param {number} bytes
 * @returns {string}
 */
export function formatBytes(bytes) {
    if (Math.abs(bytes) < 1024) {
        return `${Math.round(bytes)} B`;
    }

    let value = bytes / 1024;
    let unit = 0;
    while (Math.abs(value) >= 1024 && unit < UNITS.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(unit >= 2 ? 2 : 1)} ${UNITS[unit]}`;
}

/**
 * Keeps the measurements of the last `days` days, counted back from the latest one.
 *
 * @param {{days: string[], series: {key: string, color: string, data: number[]}[], capacity: (number|null)[]}} chart
 * @param {number} days
 */
export function sliceRange(chart, days) {
    const allDays = chart.days ?? [];
    if (allDays.length === 0) {
        return { days: [], series: [], capacity: [] };
    }

    const latest = Date.parse(allDays[allDays.length - 1]);
    const first = allDays.findIndex((day) => Date.parse(day) > latest - days * DAY_MS);

    return {
        days: allDays.slice(first),
        series: (chart.series ?? []).map((serie) => ({ ...serie, data: serie.data.slice(first) })),
        capacity: (chart.capacity ?? []).slice(first),
    };
}

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        chart: Object,
        labels: Object,
        range: { type: Number, default: 30 },
    };

    static targets = ['chart', 'rangeButton', 'empty'];

    connect() {
        this.chartInstance = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderChart(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        if (this.chartInstance) {
            this.chartInstance.destroy();
            this.chartInstance = null;
        }
    }

    selectRange(event) {
        const days = Number(event.params.days);
        if (!Number.isFinite(days) || days <= 0) {
            return;
        }

        this.rangeValue = days;
    }

    rangeValueChanged() {
        this.rangeButtonTargets.forEach((button) => {
            const active = Number(button.dataset.storageHistoryChartDaysParam) === this.rangeValue;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        if (this.chartInstance) {
            this._renderGeneration += 1;
            void this.renderChart(this._renderGeneration);
        }
    }

    async renderChart(generation) {
        if (!this.hasChartTarget || !this.chartValue) {
            return;
        }

        const data = sliceRange(this.chartValue, this.rangeValue);
        const isEmpty = data.days.length === 0;
        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = !isEmpty;
        }
        this.chartTarget.hidden = isEmpty;
        if (isEmpty) {
            return;
        }

        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration || !this.hasChartTarget) {
            return;
        }

        const options = applyChartNonce(this.buildOptions(data));

        if (this.chartInstance) {
            this.chartInstance.updateOptions(options, true, true);
        } else {
            this.chartInstance = new ApexCharts(this.chartTarget, options);
            await this.chartInstance.render();
        }
    }

    buildOptions(data) {
        const labels = this.labelsValue ?? {};
        const timestamps = data.days.map((day) => Date.parse(day));
        const totals = timestamps.map((_, index) =>
            data.series.reduce((sum, serie) => sum + (serie.data[index] ?? 0), 0),
        );
        const capacity = [...data.capacity].reverse().find((value) => value !== null) ?? null;
        const max = Math.max(...totals, capacity ?? 0);
        const isDark =
            document.body.classList.contains('ea-dark-scheme') ||
            document.body.getAttribute('data-bs-theme') === 'dark';

        return {
            chart: {
                type: 'area',
                height: 320,
                stacked: true,
                toolbar: { show: false },
                zoom: { enabled: false },
                fontFamily: 'inherit',
                background: 'transparent',
                foreColor: isDark ? '#adb5bd' : '#495057',
                animations: { enabled: false },
            },
            theme: { mode: isDark ? 'dark' : 'light' },
            series: data.series.map((serie) => ({
                name: labels[serie.key] ?? serie.key,
                data: serie.data.map((value, index) => [timestamps[index], value]),
            })),
            colors: data.series.map((serie) => serie.color),
            stroke: { width: 1, curve: 'straight' },
            fill: { type: 'solid', opacity: 0.75 },
            markers: { size: data.days.length === 1 ? 4 : 0 },
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
            yaxis: {
                min: 0,
                max: max > 0 ? max * 1.08 : undefined,
                labels: { formatter: (value) => formatBytes(value) },
            },
            annotations: {
                yaxis:
                    capacity === null
                        ? []
                        : [
                              {
                                  y: capacity,
                                  borderColor: '#d63939',
                                  strokeDashArray: 6,
                                  label: {
                                      text: `${labels.capacity ?? 'Capacity'}: ${formatBytes(capacity)}`,
                                      borderColor: '#d63939',
                                      style: { color: '#fff', background: '#d63939' },
                                  },
                              },
                          ],
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
                x: { format: 'dd.MM.yyyy' },
                y: { formatter: (value) => formatBytes(value ?? 0) },
            },
        };
    }
}
