import { Controller } from '@hotwired/stimulus';

const PRIMARY_KEYS = [
    'scope',
    'hospital',
    'cohort',
    'state',
    'dispatch_area',
    'period',
    'year',
    'month',
    'quarter',
];

const COMPARISON_KEYS = [
    'comparison_scope',
    'comparison_hospital',
    'comparison_cohort',
    'comparison_state',
    'comparison_dispatch_area',
    'comparison_period',
    'comparison_year',
    'comparison_month',
    'comparison_quarter',
];

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'primaryScopePrimaryLabel',
        'primaryScopeSecondaryLabel',
        'primaryPeriodPrimaryLabel',
        'primaryPeriodSecondaryLabel',
        'comparisonScopePrimaryLabel',
        'comparisonScopeSecondaryLabel',
        'comparisonPeriodPrimaryLabel',
        'comparisonPeriodSecondaryLabel',
    ];

    static values = {
        baseUrl: String,
        initialParams: Object,
    };

    connect() {
        this.captureInitialLabels();
        this.reset();
        this.element.addEventListener('shown.bs.modal', this.reset);
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.modal', this.reset);
    }

    reset = () => {
        this.params = { ...(this.initialParamsValue ?? {}) };
        this.restoreInitialLabels();
    };

    selectPrimaryScopePrimary(event) {
        this.handleSelection('primary', event);
    }

    selectPrimaryScopeSecondary(event) {
        this.handleSelection('primary', event);
    }

    selectPrimaryPeriodPrimary(event) {
        this.handleSelection('primary', event);
    }

    selectPrimaryPeriodSecondary(event) {
        this.handleSelection('primary', event);
    }

    selectComparisonScopePrimary(event) {
        this.handleSelection('comparison', event);
    }

    selectComparisonScopeSecondary(event) {
        this.handleSelection('comparison', event);
    }

    selectComparisonPeriodPrimary(event) {
        this.handleSelection('comparison', event);
    }

    selectComparisonPeriodSecondary(event) {
        this.handleSelection('comparison', event);
    }

    apply() {
        const url = new URL(this.baseUrlValue, window.location.origin);

        for (const [key, value] of Object.entries(this.params ?? {})) {
            url.searchParams.set(key, String(value));
        }

        window.location.assign(url.toString());
    }

    handleSelection(side, event) {
        const { params, label } = event.params;
        const parsedParams = typeof params === 'string' ? JSON.parse(params) : params;

        this.mergeSide(side, parsedParams ?? {});

        if (label && event.currentTarget) {
            const dropdownToggle = event.currentTarget
                .closest('.btn-group')
                ?.querySelector('[data-bs-toggle="dropdown"]');

            if (dropdownToggle) {
                dropdownToggle.textContent = label;
            }
        }
    }

    mergeSide(side, targetParams) {
        const keys = side === 'primary' ? PRIMARY_KEYS : COMPARISON_KEYS;

        for (const key of keys) {
            if (Object.prototype.hasOwnProperty.call(targetParams, key)) {
                this.params[key] = String(targetParams[key]);
            } else {
                delete this.params[key];
            }
        }
    }

    captureInitialLabels() {
        this.initialLabels = {};

        for (const targetName of this.constructor.targets) {
            if (this[`has${this.capitalize(targetName)}Target`]) {
                this.initialLabels[targetName] = this[`${targetName}Target`].textContent;
            }
        }
    }

    restoreInitialLabels() {
        for (const [targetName, label] of Object.entries(this.initialLabels ?? {})) {
            if (this[`has${this.capitalize(targetName)}Target`]) {
                this[`${targetName}Target`].textContent = label;
            }
        }
    }

    capitalize(value) {
        return value.charAt(0).toUpperCase() + value.slice(1);
    }
}
