import { Controller } from '@hotwired/stimulus';
import { lastMonths } from '../lib/access-trend-range.js';
import { applyChartNonce, loadApexCharts } from '../lib/load-apexcharts.js';

const COLORS = {
    signedIn: { complete: '#206bc4', incomplete: '#a6c4ea' },
    anonymous: { complete: '#6c757d', incomplete: '#c4c8cc' },
};

/**
 * Counted accesses per month (#950): stacked bars of signed-in and anonymous page views. Months that are not
 * over yet or in which the counting started are drawn lighter and marked in the label.
 *
 * The range buttons (#1001) show the last 12 or 24 months or all of them (0); the month table rows follow the
 * same range. All monthly sums arrive with the page, so switching needs no request.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        months: Array,
        labels: Object,
        range: { type: Number, default: 12 },
    };

    static targets = ['chart', 'rangeButton', 'monthRow'];

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
        const months = Number(event.params.months);
        if (!Number.isInteger(months) || months < 0) {
            return;
        }

        this.rangeValue = months;
    }

    rangeValueChanged() {
        this.rangeButtonTargets.forEach((button) => {
            const active = Number(button.dataset.accessTrendChartMonthsParam) === this.rangeValue;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        // Rows are newest first, so the first `range` rows are the months in range.
        this.monthRowTargets.forEach((row, index) => {
            row.hidden = this.rangeValue > 0 && index >= this.rangeValue;
        });

        if (this.chartInstance) {
            this._renderGeneration += 1;
            void this.renderChart(this._renderGeneration);
        }
    }

    async renderChart(generation) {
        if (!this.hasChartTarget || this.monthsValue.length === 0) {
            return;
        }

        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration || !this.hasChartTarget) {
            return;
        }

        const options = applyChartNonce(
            this.buildOptions(lastMonths(this.monthsValue, this.rangeValue)),
        );
        if (this.chartInstance) {
            this.chartInstance.updateOptions(options, true, false);
        } else {
            this.chartInstance = new ApexCharts(this.chartTarget, options);
            await this.chartInstance.render();
        }
    }

    buildOptions(months) {
        const labels = this.labelsValue ?? {};
        const categories = months.map((month) =>
            month.incomplete
                ? `${month.label} (${labels.incomplete ?? 'incomplete'})`
                : month.label,
        );
        const serie = (key) =>
            months.map((month, index) => ({
                x: categories[index],
                y: month[key],
                fillColor: month.incomplete ? COLORS[key].incomplete : COLORS[key].complete,
            }));

        return {
            chart: {
                type: 'bar',
                height: 320,
                stacked: true,
                toolbar: { show: false },
                zoom: { enabled: false },
                fontFamily: 'inherit',
                background: 'transparent',
                animations: { enabled: false },
            },
            series: [
                { name: labels.signedIn ?? 'Signed in', data: serie('signedIn') },
                { name: labels.anonymous ?? 'Anonymous', data: serie('anonymous') },
            ],
            colors: [COLORS.signedIn.complete, COLORS.anonymous.complete],
            plotOptions: { bar: { columnWidth: '60%' } },
            dataLabels: { enabled: false },
            xaxis: { type: 'category', labels: { rotate: -45, hideOverlappingLabels: true } },
            yaxis: {
                min: 0,
                forceNiceScale: true,
                labels: { formatter: (value) => Math.round(value).toLocaleString() },
            },
            legend: { show: true, position: 'top', horizontalAlign: 'left', fontSize: '12px' },
            grid: {
                strokeDashArray: 4,
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: { formatter: (value) => Math.round(value ?? 0).toLocaleString() },
            },
        };
    }
}
