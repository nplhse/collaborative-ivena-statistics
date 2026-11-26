import { Controller } from '@hotwired/stimulus';
import { Tab } from '@tabler/core';

/**
 * Tabs of a data extract request (ADR 045). The active tab follows the URL hash, so redirects to `#queries`,
 * `#release` or `#comments` open the tab that holds that element. Actions in the side column that belong to one tab
 * (`data-tab` on an action target) show only while that tab is active; the actions card hides when it has nothing
 * to show.
 */
export default class extends Controller {
    static targets = ['tab', 'action', 'actionsCard'];

    connect() {
        this.onHashChange = () => this.showFromHash();
        window.addEventListener('hashchange', this.onHashChange);
        this.showFromHash();
        this.toggleActions(this.activeTab());
    }

    disconnect() {
        window.removeEventListener('hashchange', this.onHashChange);
    }

    shown(event) {
        const pane = event.target.dataset.bsTarget?.slice(1);
        if (pane && window.location.hash !== `#${pane}`) {
            window.history.replaceState(null, '', `#${pane}`);
        }
        this.toggleActions(pane);
    }

    showFromHash() {
        const id = window.location.hash.slice(1);
        if (!id) {
            return;
        }

        const element = document.getElementById(id);
        const pane = element?.classList.contains('tab-pane')
            ? element
            : element?.closest('.tab-pane');
        const tab = pane
            ? this.tabTargets.find((candidate) => candidate.dataset.bsTarget === `#${pane.id}`)
            : null;
        if (!tab) {
            return;
        }

        Tab.getOrCreateInstance(tab).show();
        if (element !== pane) {
            window.requestAnimationFrame(() => element.scrollIntoView({ block: 'start' }));
        }
    }

    activeTab() {
        return this.tabTargets
            .find((tab) => tab.classList.contains('active'))
            ?.dataset.bsTarget?.slice(1);
    }

    toggleActions(pane) {
        let visible = false;
        this.actionTargets.forEach((action) => {
            const shown = action.dataset.tab === pane;
            action.classList.toggle('d-none', !shown);
            visible ||= shown;
        });
        this.actionsCardTargets.forEach((card) => {
            card.classList.toggle('d-none', card.dataset.fixedActions !== '1' && !visible);
        });
    }
}
