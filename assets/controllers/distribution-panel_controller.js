import { Controller } from '@hotwired/stimulus';
import ApexCharts from 'apexcharts';

export default class extends Controller {
    static values = {
        options: String,
        yAxisPercent: { type: Boolean, default: false },
    };

    static targets = ['chart'];

    initialize() {
        this.chart = null;
    }

    connect() {
        this.renderChart();
    }

    optionsValueChanged() {
        this.renderChart();
    }

    yAxisPercentValueChanged() {
        this.renderChart();
    }

    chartTargetConnected() {
        this.renderChart();
    }

    renderChart() {
        if (!this.hasChartTarget || !this.optionsValue || typeof this.optionsValue !== 'string') {
            return;
        }

        let options;
        try {
            options = JSON.parse(this.optionsValue);
        } catch (error) {
            console.error('distribution-panel options parse failed', error);
            return;
        }

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

        try {
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }

            this.chart = new ApexCharts(this.chartTarget, options);
            this.chart.render();
        } catch (error) {
            // Keep visible signal in console for intermittent chart update issues.
            console.error('distribution-panel render failed', error);
        }
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }
}
