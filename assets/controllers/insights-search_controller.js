import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'results', 'status'];

    static values = {
        url: String,
        minLength: { type: Number, default: 2 },
    };

    connect() {
        this.activeIndex = -1;
        this.items = [];
        this.abortController = null;
        this.debounceTimer = null;
        this.onDocumentClick = this.onDocumentClick.bind(this);
        document.addEventListener('click', this.onDocumentClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        this.clearTimer();
        this.abort();
    }

    onInput() {
        this.clearTimer();
        this.debounceTimer = window.setTimeout(() => this.search(), 300);
    }

    onKeydown(event) {
        if ('Escape' === event.key) {
            event.preventDefault();
            this.hideResults();
            return;
        }

        if ('ArrowDown' === event.key) {
            event.preventDefault();
            this.moveActive(1);
            return;
        }

        if ('ArrowUp' === event.key) {
            event.preventDefault();
            this.moveActive(-1);
            return;
        }

        if ('Enter' === event.key) {
            if (this.activeIndex >= 0 && this.items[this.activeIndex]) {
                event.preventDefault();
                window.location.assign(this.items[this.activeIndex].url);
            }
        }
    }

    async search() {
        const query = this.inputTarget.value.trim();
        if (query.length < this.minLengthValue) {
            this.hideResults();
            return;
        }

        this.showStatus('loading');
        this.abort();
        this.abortController = new AbortController();

        try {
            const url = new URL(this.urlValue, window.location.origin);
            url.searchParams.set('q', query);
            window.location.search
                .replace(/^\?/, '')
                .split('&')
                .filter(Boolean)
                .forEach((pair) => {
                    const [key, value] = pair.split('=');
                    if (!key || 'q' === key) {
                        return;
                    }
                    url.searchParams.set(decodeURIComponent(key), decodeURIComponent(value || ''));
                });

            const response = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
                signal: this.abortController.signal,
            });
            if (!response.ok) {
                this.hideResults();
                return;
            }

            const payload = await response.json();
            this.renderResults(payload.results ?? []);
        } catch (error) {
            if ('AbortError' !== error.name) {
                this.hideResults();
            }
        }
    }

    renderResults(results) {
        this.items = results;
        this.activeIndex = -1;
        this.resultsTarget.replaceChildren();

        if (0 === results.length) {
            this.resultsTarget.classList.add('d-none');
            this.inputTarget.setAttribute('aria-expanded', 'false');
            this.showStatus('empty');
            return;
        }

        this.hideStatus();
        results.forEach((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action';
            button.id = `stats-insights-search-option-${index}`;
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', 'false');
            button.dataset.index = String(index);

            const label = document.createElement('div');
            label.className = 'fw-medium';
            label.textContent = item.label;

            const meta = document.createElement('div');
            meta.className = 'small text-secondary';
            meta.textContent = item.context
                ? `${item.dimensionLabel} · ${item.context}`
                : item.dimensionLabel;

            button.append(label, meta);
            button.addEventListener('click', () => window.location.assign(item.url));
            this.resultsTarget.append(button);
        });

        this.resultsTarget.classList.remove('d-none');
        this.inputTarget.setAttribute('aria-expanded', 'true');
    }

    moveActive(delta) {
        if (0 === this.items.length) {
            return;
        }

        const next = Math.min(this.items.length - 1, Math.max(0, this.activeIndex + delta));
        this.activeIndex = next;
        const options = this.resultsTarget.querySelectorAll('[role="option"]');
        options.forEach((option, index) => {
            const selected = index === next;
            option.classList.toggle('active', selected);
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        this.inputTarget.setAttribute(
            'aria-activedescendant',
            `stats-insights-search-option-${next}`,
        );
    }

    showStatus(kind) {
        if (!this.hasStatusTarget) {
            return;
        }

        this.statusTarget.classList.remove('d-none');
        this.statusTarget.textContent =
            'loading' === kind
                ? this.element.dataset.insightsSearchLoadingValue || '…'
                : this.element.dataset.insightsSearchEmptyValue || '';
        this.statusTarget.dataset.kind = kind;
    }

    hideStatus() {
        if (!this.hasStatusTarget) {
            return;
        }

        this.statusTarget.classList.add('d-none');
        this.statusTarget.textContent = '';
    }

    hideResults() {
        this.items = [];
        this.activeIndex = -1;
        this.resultsTarget.replaceChildren();
        this.resultsTarget.classList.add('d-none');
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
        this.hideStatus();
    }

    onDocumentClick(event) {
        if (!this.element.contains(event.target)) {
            this.hideResults();
        }
    }

    clearTimer() {
        if (null !== this.debounceTimer) {
            window.clearTimeout(this.debounceTimer);
            this.debounceTimer = null;
        }
    }

    abort() {
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }
    }
}
