import { Controller } from '@hotwired/stimulus';

import { filterInspectorTree } from '../lib/navigation-inspector-filter.js';

/*
 * One menu of the admin navigation tree (#1237): filters the tree (search, only deviations, hidden entries,
 * only the active path) and shows the properties of the selected entry in the side panel. The tree and the
 * properties of every entry are rendered by the server; this controller only shows and hides them.
 */
export default class extends Controller {
    static targets = [
        'tree',
        'search',
        'scope',
        'showHidden',
        'onlyPath',
        'count',
        'folded',
        'none',
        'panel',
        'hint',
    ];

    connect() {
        this.onlyIssues = false;
        this.filter();
    }

    filter() {
        const roots = [
            ...this.treeTarget.querySelectorAll(
                ':scope > [data-nav-section] > ul > [data-nav-node]',
            ),
        ];
        const read = (element) => ({
            id: element.dataset.navNode,
            text: element.dataset.navText ?? '',
            issue: undefined !== element.dataset.navIssue,
            hidden: undefined !== element.dataset.navHidden,
            onPath: undefined !== element.dataset.navOnPath,
            children: [...element.querySelectorAll(':scope > ul > [data-nav-node]')].map(read),
        });

        const onlyPath = this.hasOnlyPathTarget && this.onlyPathTarget.checked;
        const result = filterInspectorTree(roots.map(read), {
            query: this.hasSearchTarget ? this.searchTarget.value : '',
            onlyIssues: this.onlyIssues,
            showHidden: !this.hasShowHiddenTarget || this.showHiddenTarget.checked,
            onlyPath,
        });

        for (const element of this.treeTarget.querySelectorAll('[data-nav-node]')) {
            element.hidden = !result.keep.has(element.dataset.navNode);
        }
        for (const section of this.treeTarget.querySelectorAll(':scope > [data-nav-section]')) {
            section.hidden =
                null === section.querySelector(':scope > ul > [data-nav-node]:not([hidden])');
        }

        this.countTarget.textContent = this.countTarget.dataset.template
            .replace('__SHOWN__', String(result.shown))
            .replace('__TOTAL__', String(result.total));
        this.noneTarget.hidden = result.shown > 0;
        if (this.hasFoldedTarget) {
            this.foldedTarget.hidden = !onlyPath || result.shown === result.total;
            this.foldedTarget.querySelector('[data-count]').textContent =
                this.foldedTarget.dataset.template.replace(
                    '__COUNT__',
                    String(result.total - result.shown),
                );
        }
    }

    showScope(event) {
        this.onlyIssues = 'issues' === event.currentTarget.dataset.scope;
        for (const button of this.scopeTargets) {
            button.setAttribute('aria-pressed', String(button === event.currentTarget));
        }
        this.filter();
    }

    showAll() {
        this.onlyPathTarget.checked = false;
        this.filter();
    }

    select(event) {
        const row = event.currentTarget;
        const template = this.element.querySelector(
            `template[data-nav-details="${CSS.escape(row.dataset.navRow)}"]`,
        );
        if (!template) {
            return;
        }

        for (const selected of this.treeTarget.querySelectorAll('.is-selected')) {
            selected.classList.remove('is-selected');
            selected.setAttribute('aria-pressed', 'false');
        }
        row.classList.add('is-selected');
        row.setAttribute('aria-pressed', 'true');
        this.panelTarget.replaceChildren(template.content.cloneNode(true));
    }
}
