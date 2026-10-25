import { Controller } from '@hotwired/stimulus';

const PAGINATION_KEYS = ['page', 'cursor', 'after', 'before'];

/*
 * Keeps list state (sort, page size, columns, filters) from the current URL in forms and
 * links that live outside a Turbo frame. The frame advances the URL on sort or paging,
 * while server-rendered hidden fields still show the state of the first page load.
 *
 * keys: copy only these query keys. Empty: copy every key except `omit`.
 * The form's own controls always win; pagination is never copied.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        keys: { type: Array, default: [] },
        omit: { type: Array, default: [] },
    };

    connect() {
        this.onFormData = this.onFormData.bind(this);
        this.onClick = this.onClick.bind(this);
        if (this.element instanceof HTMLFormElement) {
            this.element.addEventListener('formdata', this.onFormData);
        } else {
            this.element.addEventListener('click', this.onClick);
        }
    }

    disconnect() {
        this.element.removeEventListener('formdata', this.onFormData);
        this.element.removeEventListener('click', this.onClick);
    }

    onFormData(event) {
        const ownNames = new Set(
            [...this.element.elements]
                .filter(
                    (element) => element.name && !element.hasAttribute('data-query-state-field'),
                )
                .map((element) => element.name),
        );

        for (const field of this.element.querySelectorAll('[data-query-state-field]')) {
            event.formData.delete(field.name);
        }

        for (const [key, value] of this.currentEntries()) {
            if (!ownNames.has(key)) {
                event.formData.append(key, value);
            }
        }
    }

    onClick() {
        const url = new URL(this.element.getAttribute('href'), window.location.href);
        // Links only use `keys`; drop stale values the server rendered before the frame moved on.
        for (const key of this.keysValue) {
            url.searchParams.delete(key);
        }
        for (const [key, value] of this.currentEntries()) {
            url.searchParams.set(key, value);
        }
        this.element.setAttribute('href', url.pathname + url.search + url.hash);
    }

    currentEntries() {
        // Like the server side (DataTableQueryState::scalarFields), array parameters such as
        // `reasons[0]` are not copied: the form's own controls own multi-value filters.
        return [...new URLSearchParams(window.location.search).entries()].filter(
            ([key, value]) =>
                '' !== value &&
                !key.includes('[') &&
                !PAGINATION_KEYS.includes(key) &&
                this.isKept(key),
        );
    }

    isKept(key) {
        if (this.keysValue.length > 0) {
            return this.keysValue.includes(key);
        }

        return !this.omitValue.includes(key);
    }
}
