import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

/*
 * Loads reaction bars lazily (#467). Every placeholder registers itself once it comes into view; placeholders that
 * become visible together are loaded with one request, whose Turbo Streams replace them with their bars. Reactions
 * are an addition to the page: when loading fails, the placeholder simply stays empty.
 */

const BATCH_DELAY_MS = 50;
const MAX_TARGETS = 50;

/** @type {Map<string, Set<string>>} pending targets by endpoint URL */
const pending = new Map();
let flushTimer = null;

async function load(url, targets) {
    const query = new URLSearchParams({ targets: targets.join(',') });
    try {
        const response = await fetch(`${url}?${query}`, {
            headers: { Accept: 'text/vnd.turbo-stream.html' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            return;
        }
        renderStreamMessage(await response.text());
    } catch {
        // Network errors leave the placeholders empty; the page itself is complete without reactions.
    }
}

function flush() {
    flushTimer = null;
    for (const [url, targets] of pending) {
        const list = [...targets];
        for (let offset = 0; offset < list.length; offset += MAX_TARGETS) {
            load(url, list.slice(offset, offset + MAX_TARGETS));
        }
    }
    pending.clear();
}

function enqueue(url, target) {
    if (!pending.has(url)) {
        pending.set(url, new Set());
    }
    pending.get(url).add(target);
    if (null === flushTimer) {
        flushTimer = window.setTimeout(flush, BATCH_DELAY_MS);
    }
}

export default class extends Controller {
    static values = {
        target: String,
        url: String,
    };

    connect() {
        if (!('IntersectionObserver' in window)) {
            enqueue(this.urlValue, this.targetValue);

            return;
        }

        this.observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this.observer.disconnect();
                    enqueue(this.urlValue, this.targetValue);
                }
            },
            { rootMargin: '200px 0px' },
        );
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
    }
}
