import { Controller } from '@hotwired/stimulus';

const PREFIX_TIMEOUT_MS = 700;
const FOCUS_CLASS = 'dropdown-item-typeahead-focus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this.prefix = '';
        this.prefixTimeoutId = null;
        this.focusedItem = null;
        this.toggle = this.element.querySelector('[data-bs-toggle="dropdown"]');

        if (!this.toggle) {
            return;
        }

        this.onShown = this.onShown.bind(this);
        this.onHidden = this.onHidden.bind(this);
        this.onKeydown = this.onKeydown.bind(this);

        this.toggle.addEventListener('shown.bs.dropdown', this.onShown);
        this.toggle.addEventListener('hidden.bs.dropdown', this.onHidden);
    }

    disconnect() {
        if (!this.toggle) {
            return;
        }

        this.toggle.removeEventListener('shown.bs.dropdown', this.onShown);
        this.toggle.removeEventListener('hidden.bs.dropdown', this.onHidden);
        this.stopListening();
    }

    onShown() {
        this.prefix = '';
        document.addEventListener('keydown', this.onKeydown);
    }

    onHidden() {
        this.stopListening();
    }

    stopListening() {
        document.removeEventListener('keydown', this.onKeydown);
        this.clearPrefixTimeout();
        this.clearFocus();
    }

    onKeydown(event) {
        if (!this.isOpen()) {
            return;
        }

        if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (1 !== event.key.length) {
            return;
        }

        event.preventDefault();

        const char = event.key.toLowerCase();
        this.prefix += char;
        this.schedulePrefixReset();

        const items = this.menuItems();
        let match = items.find((item) => this.itemLabel(item).startsWith(this.prefix));

        if (!match) {
            this.prefix = char;
            match = items.find((item) => this.itemLabel(item).startsWith(this.prefix));
        }

        if (match) {
            this.focusItem(match);
        }
    }

    isOpen() {
        return 'true' === this.toggle.getAttribute('aria-expanded');
    }

    menuItems() {
        return Array.from(this.menuTarget.querySelectorAll('a.dropdown-item, button.dropdown-item'));
    }

    itemLabel(item) {
        return item.textContent.trim().toLowerCase();
    }

    focusItem(item) {
        this.clearFocus();
        this.focusedItem = item;
        item.classList.add(FOCUS_CLASS);
        item.scrollIntoView({ block: 'nearest' });
    }

    clearFocus() {
        if (!this.focusedItem) {
            return;
        }

        this.focusedItem.classList.remove(FOCUS_CLASS);
        this.focusedItem = null;
    }

    schedulePrefixReset() {
        this.clearPrefixTimeout();
        this.prefixTimeoutId = window.setTimeout(() => {
            this.prefix = '';
        }, PREFIX_TIMEOUT_MS);
    }

    clearPrefixTimeout() {
        if (null === this.prefixTimeoutId) {
            return;
        }

        window.clearTimeout(this.prefixTimeoutId);
        this.prefixTimeoutId = null;
    }
}
