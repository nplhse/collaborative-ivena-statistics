import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        const active = this.element.querySelector('[data-profile-tab].active');
        this.sync(active?.dataset.profileTab || 'overview', false);
    }

    shown(event) {
        const tab =
            event.currentTarget?.dataset.profileTab ||
            event.target?.dataset.profileTab ||
            'overview';
        this.sync(tab, true);
        if (tab === 'overview') {
            this.resizeCharts();
            window.requestAnimationFrame(() => this.resizeCharts());
        }
    }

    sync(tab, writeHistory) {
        if (writeHistory) {
            const url = new URL(window.location.href);
            if (url.searchParams.get('tab') !== tab) {
                url.searchParams.set('tab', tab);
                window.history.replaceState(null, '', url);
            }
        }
        this.rewriteLinks(tab);
    }

    resizeCharts() {
        const root = this.element.closest('[data-controller~="closure-analytics-charts"]');
        const charts = root
            ? this.application.getControllerForElementAndIdentifier(
                  root,
                  'closure-analytics-charts',
              )
            : null;
        if (charts && typeof charts.resize === 'function') {
            charts.resize();
        }
    }

    rewriteLinks(tab) {
        const path = window.location.pathname;
        this.element.querySelectorAll('a[href]').forEach((link) => {
            let url;
            try {
                url = new URL(link.href, window.location.origin);
            } catch {
                return;
            }
            if (url.pathname !== path) {
                return;
            }
            url.searchParams.set('tab', tab);
            link.href = `${url.pathname}${url.search}${url.hash}`;
        });
    }
}
