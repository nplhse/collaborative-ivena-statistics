import { Controller } from '@hotwired/stimulus';

const WORD = /[\p{L}\p{N}]+/gu;

/*
 * Help search terms on the target article (#1590): marks the words of the search in the article text, scrolls to the
 * first one and lets the reader remove the marks. Matches like the help search: words of letters and digits,
 * case-insensitive, each word as the start of a word ("imp" marks "Import").
 */
export default class extends Controller {
    static values = { terms: String };

    static targets = ['content', 'bar'];

    connect() {
        const words = (this.termsValue.toLowerCase().match(WORD) ?? []).filter(
            (word, index, all) => all.indexOf(word) === index,
        );
        if (words.length === 0 || !this.hasContentTarget) {
            return;
        }

        const escaped = words
            .sort((a, b) => b.length - a.length)
            .map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        const pattern = new RegExp(
            `(?<![\\p{L}\\p{N}])(?:${escaped.join('|')})[\\p{L}\\p{N}]*`,
            'giu',
        );
        const marks = this.markMatches(this.contentTarget, pattern);

        if (marks.length > 0) {
            marks[0].scrollIntoView({ block: 'center' });
        }
    }

    // Turbo caches the page when leaving it; a restored copy is marked again on connect.
    disconnect() {
        this.unmark();
    }

    clear(event) {
        event.preventDefault();
        this.unmark();
        if (this.hasBarTarget) {
            this.barTarget.remove();
        }

        const url = new URL(window.location.href);
        url.searchParams.delete('q');
        window.history.replaceState(window.history.state, '', url);
    }

    unmark() {
        if (!this.hasContentTarget) {
            return;
        }

        this.contentTarget.querySelectorAll('mark.help-search-term').forEach((mark) => {
            mark.replaceWith(document.createTextNode(mark.textContent ?? ''));
        });
        this.contentTarget.normalize();
    }

    markMatches(root, pattern) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: (node) =>
                node.parentElement?.closest('script, style, mark, button, svg')
                    ? NodeFilter.FILTER_REJECT
                    : NodeFilter.FILTER_ACCEPT,
        });
        const nodes = [];
        while (walker.nextNode()) {
            nodes.push(walker.currentNode);
        }

        const marks = [];
        nodes.forEach((node) => {
            const text = node.nodeValue ?? '';
            pattern.lastIndex = 0;
            if (!pattern.test(text)) {
                return;
            }

            pattern.lastIndex = 0;
            const fragment = document.createDocumentFragment();
            let last = 0;
            for (const match of text.matchAll(pattern)) {
                fragment.append(text.slice(last, match.index));
                const mark = document.createElement('mark');
                mark.className = 'help-search-term';
                mark.textContent = match[0];
                fragment.append(mark);
                marks.push(mark);
                last = match.index + match[0].length;
            }
            fragment.append(text.slice(last));
            node.replaceWith(fragment);
        });

        return marks;
    }
}
