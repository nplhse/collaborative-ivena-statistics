import { Controller } from '@hotwired/stimulus';
import { loadApexCharts } from '../lib/load-apexcharts.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        allocations: Object,
        imports: Object,
    };

    static targets = ['allocationsChart', 'importsChart'];

    connect() {
        this.allocationsChartInstance = null;
        this.importsChartInstance = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderCharts(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        if (this.allocationsChartInstance) {
            this.allocationsChartInstance.destroy();
            this.allocationsChartInstance = null;
        }
        if (this.importsChartInstance) {
            this.importsChartInstance.destroy();
            this.importsChartInstance = null;
        }
    }

    async renderCharts(generation) {
        const ApexCharts = await loadApexCharts();

        if (generation !== this._renderGeneration) {
            return;
        }

        this.renderAllocationsChart(ApexCharts, generation);
        this.renderImportsChart(ApexCharts, generation);
    }

    renderAllocationsChart(ApexCharts, generation) {
        if (
            generation !== this._renderGeneration ||
            !this.hasAllocationsChartTarget ||
            !this.allocationsValue
        ) {
            return;
        }

        const data = this.allocationsValue;
        const labels = data.labels || [];
        const cumulative = data.cumulativeCounts || [];

        const minVal = Math.min(...cumulative);
        const maxVal = cumulative.length ? Math.max(...cumulative) : 0;

        let yMin;
        let yMax;

        if (minVal === maxVal) {
            yMin = minVal * 0.99;
            yMax = maxVal * 1.01;
        } else {
            const range = maxVal - minVal;
            yMin = minVal - range * 0.5;
            yMax = maxVal + range * 0.25;
        }

        const options = {
            chart: {
                type: 'area',
                height: 260,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
            },
            series: [{ name: 'Allocations gesamt', data: cumulative }],
            xaxis: {
                categories: labels,
                labels: { rotate: -45, trim: true },
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            yaxis: { min: yMin, max: yMax, show: false },
            stroke: { curve: 'smooth', width: 3 },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 0.3,
                    opacityFrom: 0.3,
                    opacityTo: 0.0,
                    stops: [0, 90, 100],
                },
            },
            dataLabels: { enabled: false },
            grid: { strokeDashArray: 4, borderColor: 'rgba(0,0,0,0.04)' },
            tooltip: { shared: true, intersect: false },
            legend: { show: false },
        };

        if (generation !== this._renderGeneration || !this.hasAllocationsChartTarget) {
            return;
        }

        if (this.allocationsChartInstance) {
            this.allocationsChartInstance.updateOptions(options, true, true);
        } else {
            this.allocationsChartInstance = new ApexCharts(this.allocationsChartTarget, options);
            this.allocationsChartInstance.render();
        }
    }

    renderImportsChart(ApexCharts, generation) {
        if (
            generation !== this._renderGeneration ||
            !this.hasImportsChartTarget ||
            !this.importsValue
        ) {
            return;
        }

        const data = this.importsValue;
        const labels = data.labels || [];
        const monthly = data.monthlyCounts || [];
        const maxVal = monthly.length ? Math.max(...monthly) : 0;

        const options = {
            chart: {
                type: 'bar',
                height: 260,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
            },
            series: [{ name: 'Imports', data: monthly }],
            plotOptions: {
                bar: {
                    columnWidth: '45%',
                    borderRadius: 4,
                    borderRadiusApplication: 'end',
                },
            },
            xaxis: {
                categories: labels,
                labels: { rotate: -45, trim: true, style: { fontSize: '11px' } },
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            yaxis: {
                min: 0,
                max: maxVal === 0 ? undefined : Math.ceil(maxVal * 1.1),
                show: false,
            },
            dataLabels: { enabled: false },
            grid: { strokeDashArray: 4, borderColor: 'rgba(0,0,0,0.04)' },
            legend: { show: false },
            tooltip: { shared: true, intersect: false },
        };

        if (generation !== this._renderGeneration || !this.hasImportsChartTarget) {
            return;
        }

        if (this.importsChartInstance) {
            this.importsChartInstance.updateOptions(options, true, true);
        } else {
            this.importsChartInstance = new ApexCharts(this.importsChartTarget, options);
            this.importsChartInstance.render();
        }
    }
}
