import { Controller } from '@hotwired/stimulus';
import { loadApexCharts } from '../lib/load-apexcharts.js';
import { buildAnalysisChartOptions } from '../lib/build-analysis-chart-options.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        specs: Object,
        defaultType: String,
    };

    static targets = ['chart', 'typeButton'];

    connect() {
        this.instance = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.currentType = this.defaultTypeValue || '';
        void this.render(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        if (this.instance) {
            this.instance.destroy();
            this.instance = null;
        }
    }

    selectType(event) {
        const button = event.currentTarget;
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const type = button.dataset.chartType;
        if (!type || type === this.currentType) {
            return;
        }
        this.currentType = type;
        this.updateActiveButtons();
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.render(this._renderGeneration);
    }

    scrollToTable() {
        const table = document.querySelector('[data-testid="stats-generic-analysis-table"]');
        if (table) {
            table.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    updateActiveButtons() {
        if (!this.hasTypeButtonTarget) {
            return;
        }
        for (const button of this.typeButtonTargets) {
            const isActive = button.dataset.chartType === this.currentType;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        }
    }

    async render(generation) {
        if (!this.hasChartTarget) {
            return;
        }

        const spec = this.currentSpec();
        const options = buildAnalysisChartOptions(spec);
        if (!options) {
            if (this.instance) {
                this.instance.destroy();
                this.instance = null;
            }
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
        this.instance.render().catch((err) => console.error('[generic-analysis-chart]', err));
    }

    currentSpec() {
        const specs = this.specsValue;
        if (!specs || typeof specs !== 'object') {
            return null;
        }
        const type = this.currentType || this.defaultTypeValue;
        if (type && specs[type]) {
            return specs[type];
        }
        const keys = Object.keys(specs);
        if (keys.length === 0) {
            return null;
        }
        return specs[keys[0]];
    }
}
