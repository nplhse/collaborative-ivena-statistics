import { Controller } from '@hotwired/stimulus';

// Keeps feedback hidden fields aligned with the visible browser URL after Turbo navigation.
export default class extends Controller {
    static targets = ['redirectTarget', 'sourceRoute', 'sourceRouteParams'];

    static values = {
        route: String,
        routeParams: String,
    };

    connect() {
        this.boundSync = this.sync.bind(this);
        this.sync();
        document.addEventListener('turbo:load', this.boundSync);
        document.addEventListener('turbo:frame-load', this.boundSync);
        window.addEventListener('popstate', this.boundSync);
    }

    disconnect() {
        document.removeEventListener('turbo:load', this.boundSync);
        document.removeEventListener('turbo:frame-load', this.boundSync);
        window.removeEventListener('popstate', this.boundSync);
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
