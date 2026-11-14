import { Controller } from '@hotwired/stimulus';
import { orderByPositions } from '../page-editor/block-order.js';

/*
 * Up/down reordering for the page content blocks (#967).
 *
 * Moving a block only moves its DOM node and updates the hidden position fields; field names and ids never change.
 * That keeps nested prototypes (accordion items) and Trix editors bound to their block. The server stores the
 * blocks in position order. After a failed save the form comes back in key order, so the order is restored from
 * the submitted positions on connect.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        moveUpLabel: { type: String, default: 'Move block up' },
        moveDownLabel: { type: String, default: 'Move block down' },
        movedLabel: { type: String, default: 'Moved to position {position} of {count}.' },
    };

    connect() {
        this.collection = this.element.matches('[data-ea-collection-field]')
            ? this.element
            : this.element.querySelector('[data-ea-collection-field]');

        if (!this.collection) {
            return;
        }

        this.onCollectionChange = this.onCollectionChange.bind(this);
        document.addEventListener('ea.collection.item-added', this.onCollectionChange);
        document.addEventListener('ea.collection.item-removed', this.onCollectionChange);

        this.liveRegion = document.createElement('div');
        this.liveRegion.className = 'visually-hidden';
        this.liveRegion.setAttribute('role', 'status');
        this.liveRegion.setAttribute('aria-live', 'polite');
        this.collection.append(this.liveRegion);

        this.restoreSubmittedOrder();
        this.decorateItems();
    }

    disconnect() {
        document.removeEventListener('ea.collection.item-added', this.onCollectionChange);
        document.removeEventListener('ea.collection.item-removed', this.onCollectionChange);
        this.liveRegion?.remove();
    }

    onCollectionChange(event) {
        if (event.detail?.collection !== this.collection) {
            return;
        }

        this.decorateItems();
    }

    getItemsContainer() {
        const compound = this.collection.querySelector(
            '.ea-form-collection-items > .accordion > .form-widget-compound',
        );
        if (!compound) {
            return null;
        }

        // EasyAdmin/Symfony wrap entries in the collection root widget (e.g. #PageTranslation_content).
        return compound.querySelector(':scope > [data-empty-collection]') ?? compound;
    }

    getItems() {
        const container = this.getItemsContainer();
        if (!container) {
            return [];
        }

        return Array.from(container.children).filter((element) =>
            element.classList.contains('field-collection-item'),
        );
    }

    /** The hidden position field of a block itself, not of a nested collection inside it. */
    getPositionField(item) {
        return (
            Array.from(item.querySelectorAll('input[data-page-block-position]')).find(
                (input) => input.closest('.field-collection-item') === item,
            ) ?? null
        );
    }

    restoreSubmittedOrder() {
        const container = this.getItemsContainer();
        const items = this.getItems();
        const ordered = orderByPositions(
            items,
            items.map((item) => this.getPositionField(item)?.value ?? ''),
        );
        if (!container || ordered.every((item, index) => item === items[index])) {
            return;
        }

        ordered.forEach((item) => container.append(item));
    }

    decorateItems() {
        this.getItems().forEach((item) => {
            const header =
                item.querySelector(':scope > .accordion-item > .accordion-header') ??
                item.querySelector('.accordion-header');
            if (!header || header.querySelector('[data-collection-reorder-control]')) {
                return;
            }

            const toolbar = document.createElement('div');
            toolbar.className = 'collection-reorder-toolbar';
            toolbar.setAttribute('data-collection-reorder-control', '');

            toolbar.append(
                this.createButton('up', this.moveUpLabelValue, this.moveUp.bind(this)),
                this.createButton('down', this.moveDownLabelValue, this.moveDown.bind(this)),
            );

            const deleteButton = header.querySelector('.field-collection-delete-button');
            if (deleteButton) {
                header.insertBefore(toolbar, deleteButton);
            } else {
                header.appendChild(toolbar);
            }
        });

        this.updatePositions();
    }

    createButton(direction, label, handler) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-link collection-reorder-button px-1';
        button.setAttribute('aria-label', label);
        button.title = label;
        button.dataset.direction = direction;
        button.textContent = direction === 'up' ? '↑' : '↓';
        button.addEventListener('click', handler);

        return button;
    }

    moveUp(event) {
        event.preventDefault();
        event.stopPropagation();

        this.moveItem(event.currentTarget, 'up');
    }

    moveDown(event) {
        event.preventDefault();
        event.stopPropagation();

        this.moveItem(event.currentTarget, 'down');
    }

    moveItem(button, direction) {
        const item = button.closest('.field-collection-item');
        const container = this.getItemsContainer();
        const items = this.getItems();
        const currentIndex = items.indexOf(item);

        if (!container || currentIndex === -1) {
            return;
        }

        const targetIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;
        if (targetIndex < 0 || targetIndex >= items.length) {
            return;
        }

        const targetItem = items[targetIndex];
        if (direction === 'up') {
            container.insertBefore(item, targetItem);
        } else {
            container.insertBefore(item, targetItem.nextElementSibling);
        }

        this.updatePositions();
        this.keepFocus(item, button, direction);
        this.announce(targetIndex + 1, items.length);
    }

    /** Keeps keyboard focus on the moved block; at the first or last place the other button takes it. */
    keepFocus(item, button, direction) {
        const other = item.querySelector(
            `[data-collection-reorder-control] [data-direction="${direction === 'up' ? 'down' : 'up'}"]`,
        );
        (button.disabled ? other : button)?.focus();
    }

    announce(position, count) {
        if (!this.liveRegion) {
            return;
        }

        this.liveRegion.textContent = this.movedLabelValue
            .replace('{position}', String(position))
            .replace('{count}', String(count));
    }

    updatePositions() {
        const items = this.getItems();

        items.forEach((item, index) => {
            const position = this.getPositionField(item);
            if (position) {
                position.value = String(index);
            }

            const upButton = item.querySelector(
                '[data-collection-reorder-control] [data-direction="up"]',
            );
            const downButton = item.querySelector(
                '[data-collection-reorder-control] [data-direction="down"]',
            );

            if (upButton) {
                upButton.disabled = index === 0;
            }

            if (downButton) {
                downButton.disabled = index === items.length - 1;
            }
        });

        if (typeof globalThis.EaCollectionProperty !== 'undefined') {
            globalThis.EaCollectionProperty.updateCollectionItemCssClasses(this.collection);
        }
    }
}
