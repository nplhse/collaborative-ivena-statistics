import { Controller } from '@hotwired/stimulus';

/*
 * Help search results (#1590): coming back from an article ("Back to the search results"), the URL names the hit
 * that was opened (#help-search-hit-<page id>). Mark that hit and scroll it into view; Turbo does not set :target.
 */
export default class extends Controller {
    static targets = ['hit'];

    connect() {
        this.reset();
        const id = decodeURIComponent(window.location.hash.slice(1));
        const hit = this.hitTargets.find((element) => element.id === id);
        if (!hit) {
            return;
        }

        hit.classList.add('help-search-hit--current');
        hit.setAttribute('aria-current', 'true');
        hit.scrollIntoView({ block: 'center' });
    }

    // Turbo caches the page when leaving it; a restored copy must not keep an old mark.
    disconnect() {
        this.reset();
    }

    reset() {
        this.hitTargets.forEach((element) => {
            element.classList.remove('help-search-hit--current');
            element.removeAttribute('aria-current');
        });
    }
}
