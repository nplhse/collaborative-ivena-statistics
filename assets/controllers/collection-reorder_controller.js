import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        moveUpLabel: { type: String, default: 'Move block up' },
        moveDownLabel: { type: String, default: 'Move block down' },
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

        this.observer = new MutationObserver(() => {
            this.decorateItems();
        });
        this.observer.observe(this.collection, { childList: true, subtree: true });

        this.decorateItems();
    }

    disconnect() {
        document.removeEventListener('ea.collection.item-added', this.onCollectionChange);
        document.removeEventListener('ea.collection.item-removed', this.onCollectionChange);
        this.observer?.disconnect();
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

        // EasyAdmin/Symfony wrap entries in the collection root widget (e.g. #Page_content).
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

        this.updateButtonStates();
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

        const item = event.currentTarget.closest('.field-collection-item');
        this.moveItem(item, 'up');
    }

    moveDown(event) {
        event.preventDefault();
        event.stopPropagation();

        const item = event.currentTarget.closest('.field-collection-item');
        this.moveItem(item, 'down');
    }

    moveItem(item, direction) {
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

        this.observer.disconnect();
        try {
            if (direction === 'up') {
                container.insertBefore(item, targetItem);
            } else {
                container.insertBefore(item, targetItem.nextElementSibling);
            }

            this.reindex();
        } finally {
            this.observer.observe(this.collection, { childList: true, subtree: true });
        }
    }

    reindex() {
        const items = this.getItems();

        items.forEach((item, index) => {
            this.reindexItem(item, index);
        });

        this.collection.dataset.numItems = String(items.length);
        this.refreshCollectionItemClasses();
        this.updateButtonStates();
    }

    reindexItem(item, newIndex) {
        const firstNamedField = item.querySelector('[name*="[content]["]');
        if (!firstNamedField?.name) {
            return;
        }

        const match = firstNamedField.name.match(/\[content\]\[(\d+)\]/);
        if (!match) {
            return;
        }

        const oldIndex = match[1];
        if (oldIndex === String(newIndex)) {
            return;
        }

        const oldNameToken = `[content][${oldIndex}]`;
        const newNameToken = `[content][${newIndex}]`;
        const idPattern = new RegExp(`_content_${oldIndex}(?=_|-|$)`);
        const newIdToken = `_content_${newIndex}`;

        item.querySelectorAll('[name]').forEach((element) => {
            if (element.name.includes(oldNameToken)) {
                element.name = element.name.split(oldNameToken).join(newNameToken);
            }
        });

        item.querySelectorAll('[id]').forEach((element) => {
            const oldId = element.id;
            const newId = oldId.replace(idPattern, newIdToken);
            if (oldId === newId) {
                return;
            }

            const styleElement = document.getElementById(`ea-trix-editor-size-${oldId}`);
            if (styleElement) {
                styleElement.id = `ea-trix-editor-size-${newId}`;
                styleElement.textContent = styleElement.textContent.replaceAll(oldId, newId);
            }

            element.id = newId;
        });

        item.querySelectorAll('trix-editor[input]').forEach((element) => {
            const inputId = element.getAttribute('input');
            if (!inputId) {
                return;
            }

            element.setAttribute('input', inputId.replace(idPattern, newIdToken));
        });

        item.querySelectorAll('[for]').forEach((element) => {
            const labelFor = element.getAttribute('for');
            if (labelFor) {
                element.setAttribute('for', labelFor.replace(idPattern, newIdToken));
            }
        });

        item.querySelectorAll('[data-bs-target]').forEach((element) => {
            const target = element.getAttribute('data-bs-target');
            if (target) {
                element.setAttribute('data-bs-target', target.replace(idPattern, newIdToken));
            }
        });

        item.querySelectorAll('[aria-controls]').forEach((element) => {
            const controls = element.getAttribute('aria-controls');
            if (controls) {
                element.setAttribute('aria-controls', controls.replace(idPattern, newIdToken));
            }
        });
    }

    refreshCollectionItemClasses() {
        const items = this.getItems();
        items.forEach((item) =>
            item.classList.remove('field-collection-item-first', 'field-collection-item-last'),
        );

        if (items.length === 0) {
            return;
        }

        items[0].classList.add('field-collection-item-first');
        items[items.length - 1].classList.add('field-collection-item-last');

        if (typeof globalThis.EaCollectionProperty !== 'undefined') {
            globalThis.EaCollectionProperty.updateCollectionItemCssClasses(this.collection);
        }
    }

    updateButtonStates() {
        const items = this.getItems();

        items.forEach((item, index) => {
            const upButton = item.querySelector('[data-direction="up"]');
            const downButton = item.querySelector('[data-direction="down"]');

            if (upButton) {
                upButton.disabled = index === 0;
            }

            if (downButton) {
                downButton.disabled = index === items.length - 1;
            }
        });
    }
}
