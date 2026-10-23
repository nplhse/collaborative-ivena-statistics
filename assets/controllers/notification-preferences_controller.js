import { Controller } from '@hotwired/stimulus';

/*
 * Notification preferences (ADR 016), one controller per category table:
 * - ticking or unticking the category checkbox of a channel does the same for every changeable type
 *   of that channel, so the category really switches all its notifications;
 * - changing a type updates the category checkbox: ticked when all its types are on, unticked when
 *   all are off, partly ticked (indeterminate) otherwise;
 * - toasts are only shown together with web (in-app) notifications: ticking toast also ticks web in
 *   the same row, unticking web also unticks toast.
 * The server stores what is submitted: types that end up equal to their category lose their own
 * setting, so they follow the category afterwards.
 */
const WEB = 'in_app';
const TOAST = 'toast';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['category', 'type'];

    connect() {
        this.categoryTargets.forEach((checkbox) => {
            checkbox.indeterminate = 'true' === checkbox.dataset.mixed;
        });
    }

    categoryChanged(event) {
        this.applyCategory(event.currentTarget);
        this.couple(event.currentTarget, (partner) => this.applyCategory(partner));
    }

    typeChanged(event) {
        this.applyType(event.currentTarget);
        this.couple(event.currentTarget, (partner) => this.applyType(partner));
    }

    applyCategory(category) {
        category.indeterminate = false;
        this.typesOf(category.dataset.channel).forEach((checkbox) => {
            checkbox.checked = category.checked;
        });
    }

    applyType(type) {
        const channel = type.dataset.channel;
        const category = this.categoryTargets.find(
            (checkbox) => checkbox.dataset.channel === channel,
        );
        const types = this.typesOf(channel);
        if (!category || 0 === types.length) {
            return;
        }

        const checked = types.filter((checkbox) => checkbox.checked).length;
        if (checked === types.length || 0 === checked) {
            category.checked = checked > 0;
            category.indeterminate = false;
        } else {
            // Keep the stored category value; partly ticked only describes the types.
            category.indeterminate = true;
        }
    }

    // Keeps toast and web of one row consistent; mandatory or fixed channels are no targets and stay untouched.
    couple(checkbox, apply) {
        const { channel, row } = checkbox.dataset;
        const partnerChannel =
            TOAST === channel && checkbox.checked
                ? WEB
                : WEB === channel && !checkbox.checked
                  ? TOAST
                  : null;
        if (null === partnerChannel) {
            return;
        }

        const partner = [...this.categoryTargets, ...this.typeTargets].find(
            (candidate) =>
                candidate.dataset.row === row && candidate.dataset.channel === partnerChannel,
        );
        if (partner && partner.checked !== checkbox.checked) {
            partner.checked = checkbox.checked;
            apply(partner);
        }
    }

    typesOf(channel) {
        return this.typeTargets.filter((checkbox) => checkbox.dataset.channel === channel);
    }
}
