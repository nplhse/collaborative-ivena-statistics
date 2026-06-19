import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    initialize() {
        this._initGeneration = (this._initGeneration ?? 0) + 1;
        void this.setupDebounce(this._initGeneration);
    }

    async setupDebounce(generation) {
        const { default: debounce } = await import('debounce');

        if (generation !== this._initGeneration) {
            return;
        }

        this.debouncedSubmit = debounce(this.debouncedSubmit.bind(this), 300);
    }

    disconnect() {
        this._initGeneration = (this._initGeneration ?? 0) + 1;
        this.debouncedSubmit?.clear?.();
        this.debouncedSubmit = null;
    }

    submit() {
        this.element.requestSubmit();
    }

    debouncedSubmit() {
        this.submit();
    }
}
