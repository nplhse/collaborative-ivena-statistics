import { Controller } from '@hotwired/stimulus';
import { applyChartNonce, loadApexCharts } from '../lib/load-apexcharts.js';

const COLORS = {
    signedIn: { complete: '#206bc4', incomplete: '#a6c4ea' },
    anonymous: { complete: '#6c757d', incomplete: '#c4c8cc' },
};

/**
 * Counted accesses per month (#950): stacked bars of signed-in and anonymous page views. Months that are not
 * over yet or in which the counting started are drawn lighter and marked in the label.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        months: Array,
        labels: Object,
    };

    static targets = ['chart'];

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

    async renderChart(generation) {
        if (!this.hasChartTarget || this.monthsValue.length === 0) {
            return;
        }

        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration || !this.hasChartTarget) {
            return;
        }

        this.chartInstance = new ApexCharts(this.chartTarget, applyChartNonce(this.buildOptions()));
        await this.chartInstance.render();
    }

    buildOptions() {
        const labels = this.labelsValue ?? {};
        const months = this.monthsValue;
        const categories = months.map((month) =>
            month.incomplete
                ? `${month.label} (${labels.incomplete ?? 'incomplete'})`
                : month.label,
        );
        const isDark =
            document.body.classList.contains('ea-dark-scheme') ||
            document.body.getAttribute('data-bs-theme') === 'dark';
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
                foreColor: isDark ? '#adb5bd' : '#495057',
                animations: { enabled: false },
            },
            theme: { mode: isDark ? 'dark' : 'light' },
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
                borderColor: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)',
            },
            tooltip: {
                shared: true,
                intersect: false,
                theme: isDark ? 'dark' : 'light',
                y: { formatter: (value) => Math.round(value ?? 0).toLocaleString() },
            },
        };
    }
}
