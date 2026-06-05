import { Controller } from '@hotwired/stimulus';

const PRIMARY_PLACEHOLDER = '__PRIMARY__';
const SERIES_PLACEHOLDER = '__SERIES__';
const METRIC_PLACEHOLDER = '__METRIC__';

export default class extends Controller {
    static targets = [
        'titleInput',
        'savePrimary',
        'saveSeries',
        'saveIncludeNull',
        'saveVisualMetric',
        'saveMetrics',
        'customizeForm',
    ];

    static values = {
        titleWithSeriesTemplate: String,
        titleWithoutSeriesTemplate: String,
    };

    connect() {
        this.titleManuallyEdited = false;
        this.customizeFormTarget.addEventListener('change', () => this.customizeChanged());
    }

    titleInputEdited() {
        this.titleManuallyEdited = true;
    }

    customizeChanged() {
        if (this.hasSavePrimaryTarget) {
            this.syncSaveFormFields();
        }

        if (this.hasTitleInputTarget && !this.titleManuallyEdited) {
            this.updateTitleDraft();
        }
    }

    updateTitleDraft() {
        const primaryLabel = this.selectedOptionLabel('drawer_ga_primary');
        const seriesLabel = this.selectedOptionLabel('drawer_ga_series');
        const metricLabel = this.selectedVisualMetricLabel();

        if (seriesLabel) {
            this.titleInputTarget.value = this.titleWithSeriesTemplateValue
                .replace(PRIMARY_PLACEHOLDER, primaryLabel)
                .replace(SERIES_PLACEHOLDER, seriesLabel);
            return;
        }

        this.titleInputTarget.value = this.titleWithoutSeriesTemplateValue
            .replace(METRIC_PLACEHOLDER, metricLabel)
            .replace(PRIMARY_PLACEHOLDER, primaryLabel);
    }

    syncSaveFormFields() {
        this.savePrimaryTarget.value = this.fieldValue('drawer_ga_primary');
        this.saveSeriesTarget.value = this.fieldValue('drawer_ga_series');
        this.saveIncludeNullTarget.value = this.includeNullChecked() ? '1' : '0';
        this.saveVisualMetricTarget.value = this.fieldValue('drawer_ga_visual_metric');
        this.syncSaveMetrics();
    }

    syncSaveMetrics() {
        this.saveMetricsTarget.replaceChildren();

        this.customizeFormTarget
            .querySelectorAll('[data-generic-analysis-metrics-target="metricCheckbox"]')
            .forEach((input) => {
                if (!input.checked) {
                    return;
                }

                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'metrics[]';
                hidden.value = input.dataset.metricKey;
                this.saveMetricsTarget.appendChild(hidden);
            });
    }

    selectedOptionLabel(fieldId) {
        const select = this.customizeFormTarget.querySelector(`#${fieldId}`);
        if (!(select instanceof HTMLSelectElement)) {
            return '';
        }

        const option = select.selectedOptions[0];
        if (!option || '' === option.value) {
            return '';
        }

        return option.textContent?.trim() ?? '';
    }

    selectedVisualMetricLabel() {
        const select = this.customizeFormTarget.querySelector('#drawer_ga_visual_metric');
        if (!(select instanceof HTMLSelectElement)) {
            return '';
        }

        return select.selectedOptions[0]?.textContent?.trim() ?? '';
    }

    fieldValue(fieldId) {
        const field = this.customizeFormTarget.querySelector(`#${fieldId}`);
        if (field instanceof HTMLSelectElement || field instanceof HTMLInputElement) {
            return field.value;
        }

        return '';
    }

    includeNullChecked() {
        const checkbox = this.customizeFormTarget.querySelector('#drawer_ga_include_null');

        return checkbox instanceof HTMLInputElement && checkbox.checked;
    }
}
