import { Controller } from '@hotwired/stimulus';

/*
 * Keeps an unread count outside the header bell (e.g. the user menu entry) in step with polling.
 * Listens to the "notification-poll:changed" event the bell's poll controller dispatches on window.
 */
export default class extends Controller {
    static targets = ['badge'];

    update({ detail: { unreadCount, badgeLabel } }) {
        if (typeof unreadCount !== 'number') {
            return;
        }

        if (badgeLabel) {
            this.element.setAttribute('aria-label', badgeLabel);
        }

        if (!this.hasBadgeTarget) {
            return;
        }

        this.badgeTarget.dataset.unreadCount = String(unreadCount);
        this.badgeTarget.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        this.badgeTarget.classList.toggle('d-none', unreadCount === 0);
    }
}
