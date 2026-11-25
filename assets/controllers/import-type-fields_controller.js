import { Controller } from '@hotwired/stimulus';

/*
 * Upload form helpers:
 * - offers only the import types the user may upload to the selected hospital (#1351); each hospital
 *   option lists them in `data-import-types`, each type option names itself in `data-import-type`.
 *   Without a hospital all types stay available. The server checks the combination again.
 * - shows the extra fields of an import type (`import.type_extension`, for example encounter data)
 *   only while that type is selected.
 * - shows the upload authority checkbox (#1565) only for a hospital whose option has
 *   `data-needs-upload-authority="1"`; the server requires it for exactly those hospitals.
 * Without JavaScript every type and all fields stay visible.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['hospital', 'type', 'section', 'authority'];

    static values = { types: Array };

    connect() {
        this.filterTypes();
    }

    filterTypes() {
        if (this.hasHospitalTarget && this.hasTypeTarget) {
            const allowed = this.allowedTypes();
            const options = Array.from(this.typeTarget.options);

            options.forEach((option) => {
                const unavailable =
                    allowed !== null && !allowed.includes(option.dataset.importType);
                option.hidden = unavailable;
                option.disabled = unavailable;
            });

            if (this.typeTarget.selectedOptions[0]?.disabled) {
                const first = options.find((option) => !option.disabled);
                if (first) {
                    this.typeTarget.value = first.value;
                }
            }
        }

        this.toggleAuthority();
        this.toggle();
    }

    toggleAuthority() {
        const needed =
            this.hasHospitalTarget &&
            this.hospitalTarget.selectedOptions[0]?.dataset.needsUploadAuthority === '1';

        this.authorityTargets.forEach((authority) => {
            authority.hidden = !needed;
        });
    }

    toggle() {
        const visible = this.hasTypeTarget && this.typesValue.includes(this.typeTarget.value);

        this.sectionTargets.forEach((section) => {
            section.hidden = !visible;
        });
    }

    allowedTypes() {
        const raw = this.hospitalTarget.selectedOptions[0]?.dataset.importTypes;
        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch {
            return null;
        }
    }
}
