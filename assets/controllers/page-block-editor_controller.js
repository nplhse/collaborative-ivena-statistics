import { Controller } from '@hotwired/stimulus';
import {
    accordionItemHeaderLabel,
    blockHeaderLabel,
    lostContentFields,
    transferableFields,
} from '../page-editor/block-type-change.js';

const TYPE_FIELD = /^(.+\[content\]\[([^\]]+)\])\[type\]$/;
const ENABLED_FIELD = /^.+\[content\]\[[^\]]+\]\[enabled\]$/;
const ITEM_TITLE_FIELD = /\[data\]\[items\]\[[^\]]+\]\[title\]$/;

/*
 * Page content block editor (#967): swaps the data fields at once when the block type changes, carries over
 * fields that keep their meaning, asks before content is lost, and keeps block and accordion item headers current.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        rules: Object,
        enabledLabel: String,
        disabledLabel: String,
        confirmMessage: String,
        itemFallback: String,
    };

    connect() {
        this.onChange = this.onChange.bind(this);
        this.onInput = this.onInput.bind(this);
        this.onItemAdded = this.onItemAdded.bind(this);

        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('input', this.onInput);
        document.addEventListener('ea.collection.item-added', this.onItemAdded);

        this.refresh();
    }

    disconnect() {
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('input', this.onInput);
        document.removeEventListener('ea.collection.item-added', this.onItemAdded);
    }

    onItemAdded(event) {
        if (!this.element.contains(event.detail?.newElement ?? null)) {
            return;
        }

        this.refresh();
    }

    onChange(event) {
        const field = event.target;
        if (!(field instanceof HTMLElement) || !field.name) {
            return;
        }

        const typeMatch = field.name.match(TYPE_FIELD);
        if (typeMatch && field instanceof HTMLSelectElement) {
            this.changeType(field, typeMatch[1], typeMatch[2]);

            return;
        }

        if (ENABLED_FIELD.test(field.name)) {
            this.updateBlockHeader(field.closest('.field-collection-item'));
        }
    }

    onInput(event) {
        const field = event.target;
        if (field instanceof HTMLInputElement && ITEM_TITLE_FIELD.test(field.name)) {
            this.updateItemHeaders(field.closest('[data-ea-collection-field]'));
        }
    }

    /** Remembers the rendered type of every block and brings all headers up to date. */
    refresh() {
        this.typeSelects().forEach((select) => {
            if (!select.dataset.currentType) {
                select.dataset.currentType = select.value;
            }

            this.updateBlockHeader(select.closest('.field-collection-item'));
        });

        this.element
            .querySelectorAll('.field-collection-item [data-ea-collection-field]')
            .forEach((collection) => this.updateItemHeaders(collection));
    }

    typeSelects() {
        return Array.from(this.element.querySelectorAll('select[name]')).filter((select) =>
            TYPE_FIELD.test(select.name),
        );
    }

    changeType(select, blockName, blockKey) {
        const fromType = select.dataset.currentType || select.value;
        const toType = select.value;
        if (fromType === toType) {
            return;
        }

        const dataId = `${select.id.replace(/_type$/, '')}_data`;
        const dataWidget = document.getElementById(dataId);
        const template = this.templateFor(toType);
        if (!dataWidget || !template) {
            return;
        }

        const values = this.collectValues(dataWidget, `${blockName}[data]`);
        const lost = lostContentFields(this.rulesValue, fromType, toType, values);
        if (
            lost.length > 0 &&
            !window.confirm(this.confirmText(select, toType, lost, dataWidget))
        ) {
            select.value = fromType;

            return;
        }

        const replacement = this.buildDataWidget(template, blockKey);
        transferableFields(this.rulesValue, fromType, toType).forEach((field) => {
            const target = replacement.querySelector(`[name="${blockName}[data][${field}]"]`);
            if (target && 'string' === typeof values[field]) {
                target.value = values[field];
                if (target instanceof HTMLTextAreaElement) {
                    target.textContent = values[field];
                }
            }
        });

        const replacementWidget = replacement.firstElementChild;
        dataWidget.replaceWith(replacement);
        select.dataset.currentType = toType;

        const block = select.closest('.field-collection-item');
        this.updateBlockHeader(block);

        // Lets EasyAdmin wire the add buttons of nested collections (accordion items) in the new fields.
        document.dispatchEvent(
            new CustomEvent('ea.collection.item-added', {
                detail: {
                    newElement: replacementWidget,
                    collection: block?.closest('[data-ea-collection-field]'),
                },
            }),
        );
    }

    templateFor(type) {
        const container = this.element
            .closest('form')
            ?.querySelector('[data-page-block-type-templates]');

        return container?.querySelector(`template[data-block-type="${CSS.escape(type)}"]`) ?? null;
    }

    buildDataWidget(template, blockKey) {
        const holder = document.createElement('template');
        holder.innerHTML = template.innerHTML.replaceAll('__contentname__', blockKey);

        return holder.content;
    }

    /** Maps the data field names of one block to their current values; nested fields become nested objects. */
    collectValues(dataWidget, dataName) {
        const values = {};
        dataWidget.querySelectorAll('[name]').forEach((element) => {
            if (!element.name.startsWith(`${dataName}[`)) {
                return;
            }

            const path = element.name
                .slice(dataName.length)
                .match(/\[([^\]]*)\]/g)
                .map((part) => part.slice(1, -1));
            const value =
                'checkbox' === element.type
                    ? element.checked
                        ? element.value
                        : ''
                    : element.value;

            let node = values;
            path.forEach((key, index) => {
                if (index === path.length - 1) {
                    node[key] = value;
                } else {
                    node[key] = node[key] ?? {};
                    node = node[key];
                }
            });
        });

        return values;
    }

    confirmText(select, toType, lostFields, dataWidget) {
        const option = Array.from(select.options).find((candidate) => candidate.value === toType);
        const fieldLabels = lostFields.map((field) => {
            const input = dataWidget.querySelector(`[name$="[data][${field}]"]`);
            const label = input?.id
                ? dataWidget.querySelector(`label[for="${CSS.escape(input.id)}"]`)
                : null;

            return label?.textContent.trim() || field;
        });

        return this.confirmMessageValue
            .replace('{type}', option?.textContent.trim() ?? toType)
            .replace('{fields}', fieldLabels.join(', '));
    }

    updateBlockHeader(block) {
        const header = block?.querySelector(
            ':scope > .accordion-item > .accordion-header > .accordion-button',
        );
        const select = block?.querySelector('select[name$="][type]"]');
        const enabled = block?.querySelector('input[type="checkbox"][name$="][enabled]"]');
        if (!header || !select || !TYPE_FIELD.test(select.name)) {
            return;
        }

        const typeLabel = select.selectedOptions[0]?.textContent.trim() ?? select.value;
        this.setHeaderText(
            header,
            blockHeaderLabel(typeLabel, enabled?.checked ?? true, {
                enabled: this.enabledLabelValue,
                disabled: this.disabledLabelValue,
            }),
        );
    }

    updateItemHeaders(collection) {
        if (!collection) {
            return;
        }

        const container = collection.querySelector(
            '.ea-form-collection-items > .accordion > .form-widget-compound',
        );
        const list = container?.querySelector(':scope > [data-empty-collection]') ?? container;
        if (!list) {
            return;
        }

        Array.from(list.children)
            .filter((item) => item.classList.contains('field-collection-item'))
            .forEach((item, index) => {
                const header = item.querySelector(
                    ':scope > .accordion-item > .accordion-header > .accordion-button',
                );
                const title = item.querySelector('input[name$="][title]"]');
                if (header) {
                    this.setHeaderText(
                        header,
                        accordionItemHeaderLabel(title?.value, index + 1, this.itemFallbackValue),
                    );
                }
            });
    }

    /** Replaces the text of an accordion header button and keeps its icon. */
    setHeaderText(button, text) {
        Array.from(button.childNodes)
            .filter((node) => Node.TEXT_NODE === node.nodeType)
            .forEach((node) => node.remove());
        button.append(document.createTextNode(` ${text}`));
    }
}
