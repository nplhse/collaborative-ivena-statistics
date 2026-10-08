import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static outlets = ['closure-analytics-charts', 'closure-event-timeline'];

    connect() {
        const active = this.element.querySelector('[data-event-tab].active');
        this.sync(active?.dataset.eventTab || 'overview', false);
    }

    shown(event) {
        const tab =
            event.currentTarget?.dataset.eventTab || event.target?.dataset.eventTab || 'overview';
        this.sync(tab, true);
        if (tab === 'course') {
            this.resizeCharts();
            window.requestAnimationFrame(() => this.resizeCharts());
        }
        if (tab === 'overview') {
            this.closureEventTimelineOutlets.forEach((timeline) => timeline.revealOnce());
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
        this.closureAnalyticsChartsOutlets.forEach((charts) => {
            if (typeof charts.resize === 'function') {
                charts.resize();
            }
        });
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
