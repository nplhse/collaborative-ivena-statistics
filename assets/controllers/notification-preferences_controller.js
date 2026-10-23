import { Controller } from '@hotwired/stimulus';

/*
 * Notification preferences (ADR 016): a category checkbox also ticks or unticks the checkboxes of
 * its types that follow the category (no own setting yet, not touched on this page). The server
 * applies the same rule without JavaScript; this only keeps the page in sync before saving.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['category', 'type'];

    connect() {
        this.categoryTargets.forEach((checkbox) => {
            checkbox.indeterminate = 'true' === checkbox.dataset.mixed;
        });
    }

    categoryChanged(event) {
        const category = event.currentTarget;
        category.indeterminate = false;

        this.typeTargets
            .filter((checkbox) => checkbox.dataset.channel === category.dataset.channel)
            .filter((checkbox) => 'true' === checkbox.dataset.follows)
            .forEach((checkbox) => {
                checkbox.checked = category.checked;
            });
    }

    typeChanged(event) {
        event.currentTarget.dataset.follows = 'false';
    }
}
