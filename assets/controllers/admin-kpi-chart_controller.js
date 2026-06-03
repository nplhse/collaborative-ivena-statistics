import { Controller } from '@hotwired/stimulus';
import { loadApexCharts } from '../lib/load-apexcharts.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        chart: Object,
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
        const labels = data.labels ?? [];
        const records = data.recordsPerDay ?? [];
        const rejectionRates = data.rejectionRatePerDay ?? [];

        const maxRecords = records.length ? Math.max(...records) : 0;
        const maxRate = rejectionRates.length ? Math.max(...rejectionRates) : 0;

        const options = {
            chart: {
                type: 'line',
                height: 280,
                toolbar: { show: false },
                fontFamily: 'inherit',
                zoom: { enabled: false },
            },
            series: [
                {
                    name: 'Records per day',
                    type: 'column',
                    data: records,
                },
                {
                    name: 'Rejection rate %',
                    type: 'line',
                    data: rejectionRates,
                },
            ],
            stroke: {
                width: [0, 3],
                curve: 'smooth',
            },
            plotOptions: {
                bar: {
                    columnWidth: '55%',
                    borderRadius: 3,
                },
            },
            xaxis: {
                categories: labels,
                labels: {
                    rotate: -45,
                    trim: true,
                    style: { fontSize: '11px' },
                },
            },
            yaxis: [
                {
                    seriesName: 'Records',
                    min: 0,
                    max: maxRecords === 0 ? undefined : Math.ceil(maxRecords * 1.1),
                    title: { text: 'Records' },
                    labels: {
                        formatter: (value) => Math.round(value).toString(),
                    },
                },
                {
                    seriesName: 'Rejection rate %',
                    opposite: true,
                    min: 0,
                    max: maxRate === 0 ? 100 : Math.min(100, Math.ceil(maxRate * 1.2)),
                    title: { text: '%' },
                    labels: {
                        formatter: (value) => `${value.toFixed(1)}%`,
                    },
                },
            ],
            colors: ['#206bc4', '#f59f00'],
            dataLabels: { enabled: false },
            legend: {
                show: true,
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '12px',
            },
            grid: {
                strokeDashArray: 4,
                borderColor: 'rgba(0,0,0,0.06)',
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: (value, { seriesIndex }) => (
                        seriesIndex === 1 ? `${value.toFixed(2)}%` : Math.round(value).toString()
                    ),
                },
            },
        };

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
