import { Controller } from '@hotwired/stimulus';
import { loadApexCharts } from '../lib/load-apexcharts.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
    };

    static targets = ['flowStackedBarChart', 'originBarChart', 'transportTimeChart'];

    connect() {
        this.instances = [];
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderAll(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.instances.forEach((chart) => chart.destroy());
        this.instances = [];
    }

    async renderAll(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const payload = this.payloadValue ?? {};

        if (this.hasFlowStackedBarChartTarget && payload.flowStackedBar?.categories?.length) {
            this.renderStackedBar(
                ApexCharts,
                this.flowStackedBarChartTarget,
                payload.flowStackedBar,
                generation,
            );
        }

        if (this.hasOriginBarChartTarget && payload.originBar?.labels?.length) {
            this.renderHorizontalBar(
                ApexCharts,
                this.originBarChartTarget,
                payload.originBar,
                generation,
            );
        }

        if (this.hasTransportTimeChartTarget && payload.transportTime?.values?.length) {
            this.renderTransportTimeChart(
                ApexCharts,
                this.transportTimeChartTarget,
                payload.transportTime,
                generation,
            );
        }
    }

    renderStackedBar(ApexCharts, element, data, generation) {
        if (generation !== this._renderGeneration || !element) {
            return;
        }

        const chart = new ApexCharts(element, {
            chart: {
                type: 'bar',
                height: 320,
                stacked: true,
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: data.series ?? [],
            colors: ['#74c0fc', '#339af0', '#1864ab', '#adb5bd'],
            xaxis: {
                categories: data.categories ?? [],
                labels: { rotate: -35, trim: true, maxHeight: 80 },
            },
            plotOptions: {
                bar: { horizontal: false, columnWidth: '55%' },
            },
            legend: { position: 'top', horizontalAlign: 'right' },
            dataLabels: { enabled: false },
        });

        chart.render();
        this.instances.push(chart);
    }

    renderTransportTimeChart(ApexCharts, element, data, generation) {
        if (generation !== this._renderGeneration || !element) {
            return;
        }

        const labelCount = data.labels?.length ?? 0;
        const chart = new ApexCharts(element, {
            chart: {
                type: 'bar',
                height: Math.max(220, labelCount * 28),
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: [{ name: 'Cases', data: data.values ?? [] }],
            colors: ['#206bc4'],
            xaxis: { categories: data.labels ?? [] },
            plotOptions: {
                bar: { horizontal: true, barHeight: '65%' },
            },
            grid: {
                padding: { left: 8, right: 8 },
            },
            yaxis: {
                labels: {
                    maxWidth: 120,
                    style: { fontSize: '11px' },
                },
            },
            dataLabels: { enabled: false },
        });

        chart.render();
        this.instances.push(chart);
    }

    renderHorizontalBar(ApexCharts, element, data, generation) {
        if (generation !== this._renderGeneration || !element) {
            return;
        }

        const chart = new ApexCharts(element, {
            chart: {
                type: 'bar',
                height: Math.max(260, (data.labels?.length ?? 0) * 32),
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: [{ name: 'Cases', data: data.values ?? [] }],
            colors: ['#206bc4'],
            xaxis: { categories: data.labels ?? [] },
            plotOptions: {
                bar: { horizontal: true, barHeight: '70%' },
            },
            dataLabels: { enabled: false },
        });

        chart.render();
        this.instances.push(chart);
    }
}
