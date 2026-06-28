import { Controller } from '@hotwired/stimulus';
import { Offcanvas } from '@tabler/core';

// Keeps feedback hidden fields aligned with the visible browser URL after Turbo navigation.
export default class extends Controller {
    static targets = ['redirectTarget', 'sourceRoute', 'sourceRouteParams', 'message', 'category'];

    static values = {
        route: String,
        routeParams: String,
    };

    connect() {
        this.boundSync = this.sync.bind(this);
        this.boundOpen = this.openFromEvent.bind(this);
        this.sync();
        document.addEventListener('turbo:load', this.boundSync);
        document.addEventListener('turbo:frame-load', this.boundSync);
        window.addEventListener('popstate', this.boundSync);
        document.addEventListener('feedback:open', this.boundOpen);
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.boundSync);
        document.removeEventListener('turbo:frame-load', this.boundSync);
        window.removeEventListener('popstate', this.boundSync);
        document.removeEventListener('feedback:open', this.boundOpen);
    }

    openFromEvent(event) {
        const detail = event.detail ?? {};
        if (typeof detail.message === 'string' && this.hasMessageTarget) {
            this.messageTarget.value = detail.message;
        }
        if (typeof detail.category === 'string' && this.hasCategoryTarget) {
            this.categoryTarget.value = detail.category;
        }

        const offcanvasElement = document.getElementById('feedbackOffcanvas');
        if (!offcanvasElement) {
            return;
        }

        Offcanvas.getOrCreateInstance(offcanvasElement).show();
    }

    sync() {
        if (this.hasRedirectTargetTarget) {
            const search = window.location.search || '';
            this.redirectTargetTarget.value = `${window.location.pathname}${search}`;
        }

        const metaRoute = document.querySelector('meta[name="feedback-return-route"]')?.content;
        const metaParams = document.querySelector(
            'meta[name="feedback-return-route-params"]',
        )?.content;

        if (this.hasSourceRouteTarget) {
            const route =
                metaRoute && metaRoute !== ''
                    ? metaRoute
                    : this.hasRouteValue
                      ? this.routeValue
                      : '';
            if (route !== '') {
                this.sourceRouteTarget.value = route;
            }
        }

        if (this.hasSourceRouteParamsTarget) {
            const params =
                metaParams && metaParams !== ''
                    ? metaParams
                    : this.hasRouteParamsValue
                      ? this.routeParamsValue
                      : '{}';
            this.sourceRouteParamsTarget.value = params;
        }
    }
}
