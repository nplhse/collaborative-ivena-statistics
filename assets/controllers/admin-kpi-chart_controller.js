import { Controller } from '@hotwired/stimulus';
import { isolatedPointMarkers, lineMarkers } from '../lib/chart-markers.js';

const COLORS = ['#206bc4', '#d63939', '#f59f00'];
import { applyChartNonce, loadApexCharts } from '../lib/load-apexcharts.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        chart: Object,
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
        if (!this.hasChartTarget || !this.chartValue) {
            return;
        }

        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const data = this.chartValue;
        const names = this.labelsValue ?? {};
        const labels = data.labels ?? [];
        const imported = data.importedPerDay ?? [];
        const rejected = data.rejectedPerDay ?? [];
        const rejectionRates = data.rejectionRatePerDay ?? [];

        const maxRecords = Math.max(
            0,
            ...imported.map((value, index) => value + (rejected[index] ?? 0)),
        );
        const maxRate = Math.max(0, ...rejectionRates.filter((value) => value !== null));

        const options = applyChartNonce({
            chart: {
                type: 'line',
                height: 280,
                stacked: true,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
                background: 'transparent',
            },
            series: [
                { name: names.imported ?? 'Imported', type: 'column', data: imported },
                { name: names.rejected ?? 'Rejected', type: 'column', data: rejected },
                { name: names.rate ?? 'Rejection rate', type: 'line', data: rejectionRates },
            ],
            stroke: {
                width: [0, 0, 2],
                curve: 'straight',
            },
            markers: lineMarkers(labels.length, {
                discrete: isolatedPointMarkers(
                    [{ data: imported }, { data: rejected }, { data: rejectionRates }],
                    COLORS,
                    [2],
                ),
            }),
            plotOptions: {
                bar: {
                    columnWidth: labels.length > 90 ? '90%' : '60%',
                },
            },
            xaxis: {
                categories: labels,
                tickAmount: Math.min(labels.length, 15),
                labels: {
                    rotate: -45,
                    hideOverlappingLabels: true,
                    style: { fontSize: '11px' },
                },
            },
            yaxis: [
                {
                    seriesName: [names.imported ?? 'Imported', names.rejected ?? 'Rejected'],
                    min: 0,
                    max: maxRecords === 0 ? undefined : Math.ceil(maxRecords * 1.1),
                    title: { text: names.records ?? 'Records' },
                    labels: {
                        formatter: (value) => Math.round(value).toLocaleString(),
                    },
                },
                {
                    seriesName: names.rate ?? 'Rejection rate',
                    opposite: true,
                    min: 0,
                    max: maxRate === 0 ? 100 : Math.min(100, Math.ceil(maxRate * 1.2)),
                    title: { text: '%' },
                    labels: {
                        formatter: (value) => `${value.toFixed(1)} %`,
                    },
                },
            ],
            colors: COLORS,
            dataLabels: { enabled: false },
            legend: {
                show: true,
                position: 'top',
                horizontalAlign: 'left',
                fontSize: '12px',
            },
            grid: {
                strokeDashArray: 4,
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: (value, { seriesIndex }) => {
                        if (value === null || value === undefined) {
                            return '–';
                        }

                        return seriesIndex === 2
                            ? `${value.toFixed(2)} %`
                            : Math.round(value).toLocaleString();
                    },
                },
            },
        });

        if (generation !== this._renderGeneration || !this.hasChartTarget) {
            return;
        }

        if (this.chartInstance) {
            this.chartInstance.updateOptions(options, true, true);
        } else {
            this.chartInstance = new ApexCharts(this.chartTarget, options);
            await this.chartInstance.render();
        }
    }
}
