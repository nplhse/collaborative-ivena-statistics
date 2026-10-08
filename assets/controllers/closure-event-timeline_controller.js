import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['scroller'];

    static values = {
        eventKey: String,
    };

    connect() {
        this.revealed = window.sessionStorage.getItem(this.storageKey()) === '1';
        this.revealOnce();
    }

    revealOnce() {
        if (this.revealed || !this.hasScrollerTarget || this.scrollerTarget.clientWidth === 0) {
            return;
        }

        window.requestAnimationFrame(() => {
            if (this.revealed || this.scrollerTarget.clientWidth === 0) {
                return;
            }
            const moved = this.reveal();
            if (!moved) {
                return;
            }
            this.revealed = true;
            window.sessionStorage.setItem(this.storageKey(), '1');
        });
    }

    reveal() {
        if (!this.hasScrollerTarget) {
            return false;
        }

        const scroller = this.scrollerTarget;
        const segment = scroller.querySelector('.closure-timeline-segment--current');
        if (!segment) {
            return false;
        }

        const label = scroller.querySelector('.closure-timeline-label');
        const labelWidth = label ? label.getBoundingClientRect().width : 0;
        const scrollerRect = scroller.getBoundingClientRect();
        const segmentRect = segment.getBoundingClientRect();
        if (segmentRect.width <= 0) {
            return false;
        }

        const segmentLeft = segmentRect.left - scrollerRect.left + scroller.scrollLeft;
        const viewport = scroller.clientWidth;
        const visible = viewport - labelWidth;
        let next = scroller.scrollLeft;
        if (segmentRect.width >= visible) {
            next = segmentLeft - labelWidth;
        } else if (segmentLeft - scroller.scrollLeft < labelWidth) {
            next = segmentLeft - labelWidth;
        } else if (segmentLeft + segmentRect.width - scroller.scrollLeft > viewport) {
            next = segmentLeft + segmentRect.width - viewport;
        }

        scroller.scrollLeft = Math.max(0, next);

        return true;
    }

    storageKey() {
        const key = this.eventKeyValue || window.location.pathname;

        return `closure-event-timeline:${key}`;
    }
}
