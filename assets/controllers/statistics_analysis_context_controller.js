import { Controller } from '@hotwired/stimulus';

const PERIODS_WITH_YEAR = ['year', 'quarter', 'month'];
const PERIODS_WITH_QUARTER = ['quarter'];
const PERIODS_WITH_MONTH = ['month'];

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'locationTrigger',
        'periodTrigger',
        'modal',
        'form',
        'scope',
        'scopeGroup',
        'stateGroup',
        'stateSelect',
        'dispatchGroup',
        'dispatchSelect',
        'cohortGroup',
        'cohortSelect',
        'hospitalGroup',
        'hospitalSelect',
        'periodSelect',
        'yearGroup',
        'yearSelect',
        'quarterGroup',
        'quarterSelect',
        'monthGroup',
        'monthSelect',
        'preserved',
    ];

    static values = {
        appliedScope: String,
        appliedScopeGroup: String,
        appliedScopeDetail: String,
        defaultScopeGroup: String,
        defaultPeriod: String,
        defaultYear: Number,
        defaultQuarter: Number,
        defaultMonth: Number,
        hidePeriod: Boolean,
        monthOnly: Boolean,
    };

    connect() {
        this.snapshot = null;
        this.pendingFocus = 'location';
        this.lastTrigger = null;
        this.onModalShow = this.onModalShow.bind(this);
        this.onModalShown = this.onModalShown.bind(this);
        this.onModalHidden = this.onModalHidden.bind(this);

        if (this.hasModalTarget) {
            this.modalTarget.addEventListener('show.bs.modal', this.onModalShow);
            this.modalTarget.addEventListener('shown.bs.modal', this.onModalShown);
            this.modalTarget.addEventListener('hidden.bs.modal', this.onModalHidden);
        }

        this.captureSnapshot();
        this.syncVisibility();
    }

    disconnect() {
        if (this.hasModalTarget) {
            this.modalTarget.removeEventListener('show.bs.modal', this.onModalShow);
            this.modalTarget.removeEventListener('shown.bs.modal', this.onModalShown);
            this.modalTarget.removeEventListener('hidden.bs.modal', this.onModalHidden);
        }
    }

    openAtLocation() {
        this.pendingFocus = 'location';
        this.lastTrigger = this.hasLocationTriggerTarget ? this.locationTriggerTarget : null;
    }

    openAtPeriod() {
        this.pendingFocus = 'period';
        this.lastTrigger = this.hasPeriodTriggerTarget ? this.periodTriggerTarget : null;
    }

    onModalShow() {
        this.restoreSnapshot();
        this.syncVisibility();
        this.setTriggerExpanded(true);
    }

    onModalShown() {
        this.focusPendingField();
    }

    onModalHidden() {
        this.restoreSnapshot();
        this.syncVisibility();
        this.setTriggerExpanded(false);
        this.focusLastTrigger();
        this.pendingFocus = 'location';
        this.lastTrigger = null;
    }

    onScopeGroupChange() {
        this.syncVisibility();
        this.ensureVisibleSelectValue(
            this.stateSelectTargetOrNull(),
            this.appliedDetailForGroup('state'),
        );
        this.ensureVisibleSelectValue(
            this.dispatchSelectTargetOrNull(),
            this.appliedDetailForGroup('dispatch_area'),
        );
        this.ensureVisibleSelectValue(
            this.cohortSelectTargetOrNull(),
            this.appliedDetailForGroup('hospital_cohort'),
        );
        this.ensureVisibleSelectValue(
            this.hospitalSelectTargetOrNull(),
            this.appliedScopeGroupValue === 'my_hospitals' ? this.appliedScopeDetailValue : '',
        );
    }

    onPeriodChange() {
        this.syncVisibility();
        this.ensureVisibleSelectValue(this.yearSelectTargetOrNull(), String(this.defaultYearValue));
        this.ensureVisibleSelectValue(
            this.quarterSelectTargetOrNull(),
            String(this.defaultQuarterValue),
        );
        this.ensureVisibleSelectValue(
            this.monthSelectTargetOrNull(),
            String(this.defaultMonthValue),
        );
    }

    resetToDefault() {
        if (this.hasScopeGroupTarget) {
            this.scopeGroupTarget.value = this.defaultScopeGroupValue;
        }
        if (this.hasHospitalSelectTarget) {
            this.hospitalSelectTarget.value = '';
        }
        this.selectFirstIfEmpty(this.stateSelectTargetOrNull());
        this.selectFirstIfEmpty(this.dispatchSelectTargetOrNull());
        this.selectFirstIfEmpty(this.cohortSelectTargetOrNull());

        if (this.hasPeriodSelectTarget) {
            this.periodSelectTarget.value = this.defaultPeriodValue;
        }
        if (this.hasYearSelectTarget) {
            this.yearSelectTarget.value = String(this.defaultYearValue);
        }
        if (this.hasQuarterSelectTarget) {
            this.quarterSelectTarget.value = String(this.defaultQuarterValue);
        }
        if (this.hasMonthSelectTarget) {
            this.monthSelectTarget.value = String(this.defaultMonthValue);
        }

        this.syncVisibility();
    }

    prepareSubmit() {
        this.syncScopeValue();
        this.syncDisabledFields();
        if (this.hasHospitalSelectTarget && '' === this.hospitalSelectTarget.value) {
            this.hospitalSelectTarget.disabled = true;
        }
        if (this.scopeContextChanged()) {
            this.removeGeoFields();
        }
    }

    syncVisibility() {
        const scopeGroup = this.hasScopeGroupTarget ? this.scopeGroupTarget.value : 'public';
        this.toggleGroup(this.stateGroupTargetOrNull(), 'state' === scopeGroup);
        this.toggleGroup(this.dispatchGroupTargetOrNull(), 'dispatch_area' === scopeGroup);
        this.toggleGroup(this.cohortGroupTargetOrNull(), 'hospital_cohort' === scopeGroup);
        this.toggleGroup(this.hospitalGroupTargetOrNull(), 'my_hospitals' === scopeGroup);

        if (this.hidePeriodValue) {
            return;
        }

        if (this.monthOnlyValue) {
            this.toggleGroup(this.yearGroupTargetOrNull(), true);
            this.toggleGroup(this.monthGroupTargetOrNull(), true);
            return;
        }

        const period = this.hasPeriodSelectTarget ? this.periodSelectTarget.value : 'all';
        this.toggleGroup(this.yearGroupTargetOrNull(), PERIODS_WITH_YEAR.includes(period));
        this.toggleGroup(this.quarterGroupTargetOrNull(), PERIODS_WITH_QUARTER.includes(period));
        this.toggleGroup(this.monthGroupTargetOrNull(), PERIODS_WITH_MONTH.includes(period));
    }

    syncDisabledFields() {
        this.setGroupDisabled(this.stateGroupTargetOrNull(), this.stateSelectTargetOrNull());
        this.setGroupDisabled(this.dispatchGroupTargetOrNull(), this.dispatchSelectTargetOrNull());
        this.setGroupDisabled(this.cohortGroupTargetOrNull(), this.cohortSelectTargetOrNull());
        this.setGroupDisabled(this.hospitalGroupTargetOrNull(), this.hospitalSelectTargetOrNull());
        this.setGroupDisabled(this.yearGroupTargetOrNull(), this.yearSelectTargetOrNull());
        this.setGroupDisabled(this.quarterGroupTargetOrNull(), this.quarterSelectTargetOrNull());
        this.setGroupDisabled(this.monthGroupTargetOrNull(), this.monthSelectTargetOrNull());
    }

    syncScopeValue() {
        if (!this.hasScopeTarget || !this.hasScopeGroupTarget) {
            return;
        }

        let scope = this.scopeGroupTarget.value;
        if (
            'my_hospitals' === scope &&
            this.hasHospitalSelectTarget &&
            '' !== this.hospitalSelectTarget.value
        ) {
            scope = 'hospital';
        }
        this.scopeTarget.value = scope;
    }

    scopeContextChanged() {
        if (!this.hasScopeGroupTarget) {
            return false;
        }

        const nextGroup = this.scopeGroupTarget.value;
        if (nextGroup !== this.appliedScopeGroupValue) {
            return true;
        }

        const detailSelect = this.detailSelectForGroup(nextGroup);
        if (!detailSelect) {
            return false;
        }

        return detailSelect.value !== this.appliedScopeDetailValue;
    }

    removeGeoFields() {
        this.preservedTargets.forEach((input) => {
            if (input.dataset.analysisContextGeo) {
                input.disabled = true;
            }
        });
    }

    toggleGroup(group, visible) {
        if (!group) {
            return;
        }

        group.hidden = !visible;
        group.classList.toggle('d-none', !visible);
        group.querySelectorAll('select').forEach((element) => {
            if (element instanceof HTMLSelectElement) {
                element.disabled = !visible;
            }
        });
    }

    setGroupDisabled(group, select) {
        if (!select) {
            return;
        }

        const hidden = !group || group.hidden || group.classList.contains('d-none');
        select.disabled = hidden;
    }

    ensureVisibleSelectValue(select, preferred) {
        if (!select || select.disabled || 0 === select.options.length) {
            return;
        }

        const preferredValue =
            null === preferred || undefined === preferred ? '' : String(preferred);
        if (this.hasOption(select, preferredValue)) {
            select.value = preferredValue;
            return;
        }

        if ('' === select.value || !this.hasOption(select, select.value)) {
            select.selectedIndex = 0;
        }
    }

    selectFirstIfEmpty(select) {
        if (!select || 0 === select.options.length) {
            return;
        }

        if ('' === select.value || !this.hasOption(select, select.value)) {
            select.selectedIndex = 0;
        }
    }

    hasOption(select, value) {
        return Array.from(select.options).some((option) => option.value === value);
    }

    detailSelectForGroup(scopeGroup) {
        switch (scopeGroup) {
            case 'state':
                return this.stateSelectTargetOrNull();
            case 'dispatch_area':
                return this.dispatchSelectTargetOrNull();
            case 'hospital_cohort':
                return this.cohortSelectTargetOrNull();
            case 'my_hospitals':
                return this.hospitalSelectTargetOrNull();
            default:
                return null;
        }
    }

    appliedDetailForGroup(scopeGroup) {
        return this.appliedScopeGroupValue === scopeGroup ? this.appliedScopeDetailValue : '';
    }

    captureSnapshot() {
        if (!this.hasFormTarget) {
            this.snapshot = null;
            return;
        }

        this.snapshot = this.serializeForm(this.formTarget);
    }

    restoreSnapshot() {
        if (!this.snapshot || !this.hasFormTarget) {
            return;
        }

        this.applySerializedForm(this.formTarget, this.snapshot);
        if (this.hasScopeGroupTarget) {
            this.scopeGroupTarget.value = this.appliedScopeGroupValue;
        }
        if (this.hasScopeTarget) {
            this.scopeTarget.value = this.appliedScopeValue;
        }
    }

    serializeForm(form) {
        const values = {};
        Array.from(form.elements).forEach((element) => {
            if (!element.name && !element.dataset.statisticsAnalysisContextTarget) {
                return;
            }

            const key =
                element.name || element.id || element.dataset.statisticsAnalysisContextTarget;
            if (!key || 'fieldset' === element.tagName.toLowerCase()) {
                return;
            }

            values[key] = {
                value: element.value,
                disabled: Boolean(element.disabled),
            };
        });

        if (this.hasScopeGroupTarget) {
            values.__scopeGroup = { value: this.scopeGroupTarget.value, disabled: false };
        }

        return values;
    }

    applySerializedForm(form, values) {
        Array.from(form.elements).forEach((element) => {
            const key =
                element.name || element.id || element.dataset.statisticsAnalysisContextTarget;
            if (!key || !values[key]) {
                return;
            }

            element.disabled = false;
            element.value = values[key].value;
        });

        if (values.__scopeGroup && this.hasScopeGroupTarget) {
            this.scopeGroupTarget.value = values.__scopeGroup.value;
        }
    }

    setTriggerExpanded(expanded) {
        const value = expanded ? 'true' : 'false';
        if (this.hasLocationTriggerTarget) {
            this.locationTriggerTarget.setAttribute('aria-expanded', value);
        }
        if (this.hasPeriodTriggerTarget) {
            this.periodTriggerTarget.setAttribute('aria-expanded', value);
        }
    }

    focusLastTrigger() {
        if (this.lastTrigger instanceof HTMLElement) {
            this.lastTrigger.focus();
        }
    }

    focusPendingField() {
        if ('period' === this.pendingFocus) {
            this.focusPeriodField();
            return;
        }

        this.focusLocationField();
    }

    focusLocationField() {
        if (this.hasScopeGroupTarget) {
            this.scopeGroupTarget.focus();
        }
    }

    focusPeriodField() {
        if (this.hasPeriodSelectTarget && !this.periodSelectTarget.disabled) {
            this.periodSelectTarget.focus();
            return;
        }
        if (this.hasYearSelectTarget && !this.yearSelectTarget.disabled) {
            this.yearSelectTarget.focus();
        }
    }

    stateGroupTargetOrNull() {
        return this.hasStateGroupTarget ? this.stateGroupTarget : null;
    }

    stateSelectTargetOrNull() {
        return this.hasStateSelectTarget ? this.stateSelectTarget : null;
    }

    dispatchGroupTargetOrNull() {
        return this.hasDispatchGroupTarget ? this.dispatchGroupTarget : null;
    }

    dispatchSelectTargetOrNull() {
        return this.hasDispatchSelectTarget ? this.dispatchSelectTarget : null;
    }

    cohortGroupTargetOrNull() {
        return this.hasCohortGroupTarget ? this.cohortGroupTarget : null;
    }

    cohortSelectTargetOrNull() {
        return this.hasCohortSelectTarget ? this.cohortSelectTarget : null;
    }

    hospitalGroupTargetOrNull() {
        return this.hasHospitalGroupTarget ? this.hospitalGroupTarget : null;
    }

    hospitalSelectTargetOrNull() {
        return this.hasHospitalSelectTarget ? this.hospitalSelectTarget : null;
    }

    yearGroupTargetOrNull() {
        return this.hasYearGroupTarget ? this.yearGroupTarget : null;
    }

    yearSelectTargetOrNull() {
        return this.hasYearSelectTarget ? this.yearSelectTarget : null;
    }

    quarterGroupTargetOrNull() {
        return this.hasQuarterGroupTarget ? this.quarterGroupTarget : null;
    }

    quarterSelectTargetOrNull() {
        return this.hasQuarterSelectTarget ? this.quarterSelectTarget : null;
    }

    monthGroupTargetOrNull() {
        return this.hasMonthGroupTarget ? this.monthGroupTarget : null;
    }

    monthSelectTargetOrNull() {
        return this.hasMonthSelectTarget ? this.monthSelectTarget : null;
    }
}
