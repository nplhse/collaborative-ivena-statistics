import { Controller } from '@hotwired/stimulus';
import ApexCharts from 'apexcharts';

export default class extends Controller {
    static values = {
        options: Object,
        yAxisPercent: { type: Boolean, default: false },
    };

    static targets = ['chart'];

    connect() {
        this.chart = null;
        if (!this.hasChartTarget || !this.optionsValue || typeof this.optionsValue !== 'object') {
            return;
        }

        const options = JSON.parse(JSON.stringify(this.optionsValue));

        if (this.yAxisPercentValue) {
            const y = options.yaxis || {};
            options.yaxis = {
                ...y,
                max: 100,
                labels: {
                    ...(y.labels || {}),
                    formatter: (val) => {
                        const n = Math.round(Number(val) * 10) / 10;
                        return `${n}%`;
                    },
                },
            };
        }

        this.chart = new ApexCharts(this.chartTarget, options);
        this.chart.render();
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }
}
