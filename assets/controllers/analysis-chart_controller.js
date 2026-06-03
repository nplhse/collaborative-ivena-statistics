import { Controller } from '@hotwired/stimulus';
import { loadApexCharts } from '../lib/load-apexcharts.js';
import { buildAnalysisChartOptions } from '../lib/build-analysis-chart-options.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        spec: Object,
    };

    static targets = ['chart'];

    connect() {
        this.instance = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.render(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        if (this.instance) {
            this.instance.destroy();
            this.instance = null;
        }
    }

    async render(generation) {
        if (!this.hasChartTarget || !this.specValue) {
            return;
        }

        const options = buildAnalysisChartOptions(this.specValue);
        if (!options) {
            return;
        }

        const ApexCharts = await loadApexCharts();

        if (generation !== this._renderGeneration || !this.hasChartTarget) {
            return;
        }

        if (this.instance) {
            this.instance.destroy();
            this.instance = null;
        }

        this.instance = new ApexCharts(this.chartTarget, options);
        this.instance.render().catch((err) => console.error('[analysis-chart]', err));
    }
}
