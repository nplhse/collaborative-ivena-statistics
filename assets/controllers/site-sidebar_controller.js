import { Controller } from '@hotwired/stimulus';
import { Collapse } from '@tabler/core';

/*
 * Contextual sidebar (ADR 035). Tabler handles the rail, the hover preview and the mobile collapse; this
 * controller adds what Tabler does not:
 * - the fold toggle: pinned open -> rail at once (no hover preview until the pointer has left the rail),
 *   folded -> pinned open; same <html data-bs-sidebar>, localStorage key and event as Tabler's own button;
 * - a click on a group while the sidebar is folded (in the rail or in the hover preview) pins the sidebar
 *   open and opens that group instead of toggling it;
 * - the top offset of the sidebar below the sticky top navbar;
 * - a window resize after folding, so charts and maps take the new content width.
 */
const STORAGE_KEY = 'tabler-sidebar';
const RAIL_MEDIA = '(min-width: 992px)';
const RESIZE_DELAY_MS = 350;

export default class extends Controller {
    static targets = ['foldToggle'];

    connect() {
        this.header = this.element.parentElement?.querySelector(':scope > header.navbar') ?? null;
        if (this.header) {
            this.resizeObserver = new ResizeObserver(() => this.syncHeaderHeight());
            this.resizeObserver.observe(this.header);
            this.syncHeaderHeight();
        }
        this.syncFoldToggle();

        // Bootstrap handles collapse toggles with a delegated listener in the capture phase on document, so a
        // click in the rail is caught earlier, on window, before it would toggle the group.
        this.onRailClick = (event) => this.railClick(event);
        window.addEventListener('click', this.onRailClick, true);
    }

    disconnect() {
        this.resizeObserver?.disconnect();
        this.releaseHover();
        window.removeEventListener('click', this.onRailClick, true);
        window.clearTimeout(this.resizeTimer);
    }

    toggleFold(event) {
        if (this.isFolded()) {
            this.setFold('default');
            return;
        }

        this.setFold('folded-hover');
        event.currentTarget.blur();
        this.suppressHoverUntilLeave();
    }

    railClick(event) {
        const toggle =
            event.target instanceof Element ? event.target.closest('.site-sidebar__group') : null;
        if (!toggle || !this.element.contains(toggle) || !this.isFoldedOnDesktop()) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        this.setFold('default');

        const target = document.querySelector(toggle.dataset.bsTarget);
        if (target) {
            Collapse.getOrCreateInstance(target, { toggle: false }).show();
        }
    }

    isFolded() {
        return (document.documentElement.getAttribute('data-bs-sidebar') ?? '').startsWith(
            'folded',
        );
    }

    isFoldedOnDesktop() {
        return this.isFolded() && window.matchMedia(RAIL_MEDIA).matches;
    }

    setFold(value) {
        const root = document.documentElement;
        if ('default' === value) {
            root.removeAttribute('data-bs-sidebar');
        } else {
            root.setAttribute('data-bs-sidebar', value);
        }

        try {
            localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // Storage may be unavailable; the state then lasts for this page only.
        }

        this.syncFoldToggle();
        document.dispatchEvent(
            new CustomEvent('site-sidebar:folded', { detail: { folded: 'default' !== value } }),
        );

        // The content width changes without a window resize; charts and maps only redraw on window resize.
        // Fire one after Tabler's width transition (0.3s).
        window.clearTimeout(this.resizeTimer);
        this.resizeTimer = window.setTimeout(
            () => window.dispatchEvent(new Event('resize')),
            RESIZE_DELAY_MS,
        );
    }

    syncFoldToggle() {
        if (!this.hasFoldToggleTarget) {
            return;
        }

        const folded = this.isFolded();
        const label = folded
            ? this.foldToggleTarget.dataset.labelKeepOpen
            : this.foldToggleTarget.dataset.labelCollapse;
        this.foldToggleTarget.setAttribute('aria-pressed', String(folded));
        this.foldToggleTarget.title = label;
    }

    syncHeaderHeight() {
        document.documentElement.style.setProperty(
            '--site-header-height',
            `${this.header.offsetHeight}px`,
        );
    }

    suppressHoverUntilLeave() {
        this.releaseHover();
        this.element.classList.add('is-hover-suppressed');
        this.onPointerMove = (event) => {
            const rect = this.element.getBoundingClientRect();
            if (event.clientX > rect.right + 4 || event.clientY < rect.top) {
                this.releaseHover();
            }
        };
        document.addEventListener('mousemove', this.onPointerMove);
    }

    releaseHover() {
        this.element.classList.remove('is-hover-suppressed');
        if (this.onPointerMove) {
            document.removeEventListener('mousemove', this.onPointerMove);
            this.onPointerMove = null;
        }
    }
}
