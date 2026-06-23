import { Controller } from '@hotwired/stimulus';
import { loadApexCharts } from '../lib/load-apexcharts.js';
import {
    buildAnalysisChartExportOptions,
    buildAnalysisChartOptions,
    resolveAnalysisChartFontFamily,
} from '../lib/build-analysis-chart-options.js';
import { buildExportFilename, downloadDataUri } from '../lib/download-data-uri.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        specs: Object,
        defaultType: String,
        exportFilename: String,
    };

    static targets = ['chart', 'typeButton'];

    connect() {
        this.instance = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.currentType = this.defaultTypeValue || '';
        void this.render(this._renderGeneration);
    }

    specsValueChanged() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.render(this._renderGeneration);
    }

    defaultTypeValueChanged() {
        this.currentType = this.defaultTypeValue || '';
        this.updateActiveButtons();
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

    async exportPng() {
        if (!this.instance || !this.hasChartTarget) {
            return;
        }

        const spec = this.currentSpec();
        const exportTitle = (this.exportFilenameValue || '').trim();
        const exportOptions = buildAnalysisChartExportOptions(spec ?? {}, exportTitle);
        if (!exportOptions) {
            return;
        }

        const container = document.createElement('div');
        container.setAttribute('aria-hidden', 'true');
        container.style.position = 'fixed';
        container.style.left = '-10000px';
        container.style.top = '0';
        container.style.width = `${Math.max(this.chartTarget.clientWidth, 640)}px`;
        container.style.fontFamily = resolveAnalysisChartFontFamily();
        container.style.pointerEvents = 'none';
        document.body.appendChild(container);

        let exportChart = null;

        try {
            const ApexCharts = await loadApexCharts();
            exportChart = new ApexCharts(container, exportOptions);
            await exportChart.render();
            await this.waitForPaint();

            const { imgURI } = await exportChart.dataURI({
                scale: 2,
            });
            if (!imgURI) {
                return;
            }

            downloadDataUri(
                imgURI,
                buildExportFilename(this.exportFilenameValue || 'analysis-chart', 'png'),
            );
        } catch (error) {
            console.error('[generic-analysis-chart] PNG export failed', error);
        } finally {
            if (exportChart) {
                exportChart.destroy();
            }
            container.remove();
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

    waitForPaint() {
        return new Promise((resolve) => {
            requestAnimationFrame(() => {
                requestAnimationFrame(resolve);
            });
        });
    }
}
