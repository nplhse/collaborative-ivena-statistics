import { Controller } from '@hotwired/stimulus';
import { Collapse } from '@tabler/core';

import { filterTree } from '../lib/navigation-filter.js';

/*
 * Contextual sidebar (ADR 035). Tabler handles the rail, the hover preview and the mobile collapse; this
 * controller adds what Tabler does not:
 * - the fold toggle: pinned open -> rail at once (no hover preview until the pointer has left the rail),
 *   folded -> pinned open; same <html data-bs-sidebar>, localStorage key and event as Tabler's own button;
 * - a click on a group while the sidebar is folded (in the rail or in the hover preview) pins the sidebar
 *   open and opens that group instead of toggling it;
 * - the top offset of the sidebar below the sticky top navbar;
 * - a window resize after folding, so charts and maps take the new content width;
 * - the search field (#1248): filters the tree with lib/navigation-filter.js and keeps the path to every
 *   match, without touching the fold state. `/` focuses it (in the rail this opens Tabler's preview, which
 *   follows focus), Escape clears it, the arrow keys move between the results and Enter opens the first one.
 *   The groups opened by the search close again when it is cleared.
 */
const STORAGE_KEY = 'tabler-sidebar';
const RAIL_MEDIA = '(min-width: 992px)';
const RESIZE_DELAY_MS = 350;

export default class extends Controller {
    static targets = ['foldToggle', 'search', 'tree', 'empty', 'status'];

    connect() {
        this.header = this.element.parentElement?.querySelector(':scope > header.navbar') ?? null;
        // The demo environment shows a banner above the page (ADR 023); the sidebar starts below it as well (#1381).
        this.banner = document.getElementById('demo-environment-banner');
        if (this.header) {
            this.resizeObserver = new ResizeObserver(() => this.syncHeaderHeight());
            this.resizeObserver.observe(this.header);
            if (this.banner) {
                this.resizeObserver.observe(this.banner);
            }
            this.syncHeaderHeight();
        }
        this.syncFoldToggle();

        // Bootstrap handles collapse toggles with a delegated listener in the capture phase on document, so a
        // click in the rail is caught earlier, on window, before it would toggle the group.
        this.onRailClick = (event) => this.railClick(event);
        window.addEventListener('click', this.onRailClick, true);

        this.onShortcut = (event) => this.shortcut(event);
        document.addEventListener('keydown', this.onShortcut);
        // Turbo keeps a snapshot of the page for the back button; it should show the menu unfiltered.
        this.onBeforeCache = () => this.clearSearch();
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
    }

    disconnect() {
        this.resizeObserver?.disconnect();
        this.releaseHover();
        window.removeEventListener('click', this.onRailClick, true);
        window.clearTimeout(this.resizeTimer);
        document.removeEventListener('keydown', this.onShortcut);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
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
        document.documentElement.style.setProperty(
            '--site-banner-height',
            `${this.banner?.offsetHeight ?? 0}px`,
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

    shortcut(event) {
        if (
            '/' !== event.key ||
            event.defaultPrevented ||
            event.ctrlKey ||
            event.metaKey ||
            event.altKey ||
            !this.hasSearchTarget ||
            isEditable(event.target)
        ) {
            return;
        }

        event.preventDefault();
        // On small screens the views are collapsed under the mobile bar; open them first.
        const views = this.searchTarget.closest('.navbar-collapse');
        if (views && !window.matchMedia(RAIL_MEDIA).matches && !views.classList.contains('show')) {
            Collapse.getOrCreateInstance(views, { toggle: false }).show();
        }
        this.searchTarget.focus();
        this.searchTarget.select();
    }

    searchKeydown(event) {
        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                if ('' !== this.searchTarget.value) {
                    this.clearSearch();
                } else {
                    this.searchTarget.blur();
                }
                break;
            case 'ArrowDown':
                if (this.isSearching()) {
                    event.preventDefault();
                    this.resultLinks()[0]?.focus();
                }
                break;
            case 'Enter': {
                const first = this.resultLinks().find((link) => 'A' === link.tagName);
                if (this.isSearching() && first) {
                    event.preventDefault();
                    first.click();
                }
                break;
            }
        }
    }

    treeKeydown(event) {
        if (!this.isSearching() || !['ArrowDown', 'ArrowUp', 'Escape'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        if ('Escape' === event.key) {
            this.searchTarget.focus();
            this.clearSearch();
            return;
        }

        const links = this.resultLinks();
        const index = links.indexOf(event.target.closest('.nav-link'));
        if ('ArrowUp' === event.key && index <= 0) {
            this.searchTarget.focus();
            return;
        }
        links[Math.min(index + ('ArrowDown' === event.key ? 1 : -1), links.length - 1)]?.focus();
    }

    clearSearch() {
        if (!this.hasSearchTarget) {
            return;
        }
        this.searchTarget.value = '';
        this.filter();
    }

    filter() {
        if (!this.hasTreeTarget) {
            return;
        }

        const index = this.readTree();
        const result = filterTree(index.roots, index.sections, this.searchTarget.value);
        if (!result.active) {
            this.restoreTree(index);
            return;
        }

        this.openGroupsBeforeSearch ??= new Set(
            index.items
                .filter((item) => item.collapse?.classList.contains('show'))
                .map((item) => item.id),
        );

        for (const item of index.items) {
            item.element.hidden = !result.visible.has(item.id);
            this.setGroupOpen(item, result.open.has(item.id));
            this.highlight(item, result.highlights.get(item.id) ?? []);
        }
        this.syncSectionTitles(result.sections);

        this.emptyTarget.hidden = result.visible.size > 0;
        this.announce(result.links);
    }

    isSearching() {
        return this.hasSearchTarget && '' !== this.searchTarget.value.trim();
    }

    /**
     * Visible entries and groups of the tree in document order.
     */
    resultLinks() {
        return [...this.treeTarget.querySelectorAll('[data-nav-id] > .nav-link')].filter(
            (link) => null === link.closest('[hidden]') && link.getClientRects().length > 0,
        );
    }

    /**
     * The tree as rendered: every [data-nav-id] item with its parent item, and the sections with their titles.
     * Read once; the server renders it and nothing changes it afterwards.
     */
    readTree() {
        if (this.index) {
            return this.index;
        }

        const items = [];
        const byElement = new Map();
        const roots = [];
        for (const element of this.treeTarget.querySelectorAll('[data-nav-id]')) {
            const item = {
                id: element.dataset.navId,
                label: element.dataset.navLabel ?? '',
                section: element.dataset.navSection ?? '',
                children: [],
                element,
                title: element.querySelector(':scope > .nav-link .nav-link-title'),
                toggle: element.querySelector(':scope > .site-sidebar__group'),
                collapse: element.querySelector(':scope > .collapse'),
            };
            items.push(item);
            byElement.set(element, item);

            const parent = byElement.get(element.parentElement?.closest('[data-nav-id]'));
            (parent ? parent.children : roots).push(item);
        }

        const titles = [...this.treeTarget.querySelectorAll(':scope > [data-nav-section-title]')];
        const sections = new Map(
            items.map((item) => [item.section, { id: item.section, label: '' }]),
        );
        for (const title of titles) {
            sections.set(title.dataset.navSectionTitle, {
                id: title.dataset.navSectionTitle,
                label: title.dataset.navLabel ?? '',
            });
        }

        this.index = { items, roots, sections: [...sections.values()], titles };

        return this.index;
    }

    restoreTree(index) {
        for (const item of index.items) {
            item.element.hidden = false;
            this.highlight(item, []);
            if (this.openGroupsBeforeSearch) {
                this.setGroupOpen(item, this.openGroupsBeforeSearch.has(item.id));
            }
        }
        for (const title of index.titles) {
            title.hidden = false;
        }
        this.openGroupsBeforeSearch = null;
        this.emptyTarget.hidden = true;
        this.statusTarget.textContent = '';
    }

    /**
     * Opens or closes a group without Bootstrap's transition, like the server renders a group on the active path.
     */
    setGroupOpen(item, open) {
        if (!item.collapse) {
            return;
        }
        item.collapse.classList.toggle('show', open);
        item.toggle?.classList.toggle('collapsed', !open);
        item.toggle?.setAttribute('aria-expanded', String(open));
    }

    highlight(item, ranges) {
        if (!item.title) {
            return;
        }
        if (0 === ranges.length) {
            if (item.title.querySelector('mark')) {
                item.title.textContent = item.label;
            }
            return;
        }

        const parts = [];
        let at = 0;
        for (const [start, end] of ranges) {
            parts.push(document.createTextNode(item.label.slice(at, start)));
            const mark = document.createElement('mark');
            mark.className = 'site-sidebar__match';
            mark.textContent = item.label.slice(start, end);
            parts.push(mark);
            at = end;
        }
        parts.push(document.createTextNode(item.label.slice(at)));
        item.title.replaceChildren(...parts);
    }

    /**
     * Section titles of sections without a visible entry are hidden, and so is a divider that would end up
     * above the first visible entry.
     */
    syncSectionTitles(visibleSections) {
        let seenEntry = false;
        for (const element of this.treeTarget.children) {
            if (undefined !== element.dataset.navSectionTitle) {
                const isDivider = '' === element.dataset.navLabel;
                element.hidden =
                    !visibleSections.has(element.dataset.navSectionTitle) ||
                    (isDivider && !seenEntry);
            } else if (!element.hidden) {
                seenEntry = true;
            }
        }
    }

    announce(count) {
        const labels = this.statusTarget.dataset;
        const message =
            0 === count
                ? labels.labelNone
                : 1 === count
                  ? labels.labelOne
                  : labels.labelOther.replace('__COUNT__', String(count));
        this.statusTarget.textContent = message;
    }
}

function isEditable(target) {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable || null !== target.closest('input, textarea, select'))
    );
}
