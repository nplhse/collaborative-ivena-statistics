/*
 * Filter for the contextual sidebar (ADR 035, #1248). Pure functions without DOM access, so they can be
 * tested with `node --test`; the site-sidebar controller reads the tree from the page and applies the result.
 *
 * A tree node is { id, label, section, children }; a section is { id, label } (label may be empty).
 *
 * Rules:
 * - Matching ignores case and accents ("Anlässe" matches "anlasse", "ß" matches "ss").
 * - Every word of the term must occur in the node's own label or in a label on its path (section, groups);
 *   at least one word must occur in the node's own label. A single word therefore only has to occur in the
 *   node's name: "Fachbereich" finds Insights › Dimensions › Specialities.
 * - A matching node stays visible with all its ancestors, and the groups on the path open.
 * - A matching group keeps all its descendants visible and open.
 * - A section whose title matches keeps all its entries; other sections are visible only with a visible entry.
 */

const COMBINING_MARKS = /[̀-ͯ]/g;

/**
 * Normalises a text for matching and maps every character of the result back to the original text.
 *
 * @returns {{ text: string, map: number[] }} map[i] is the index in the original text of result char i
 */
export function normalizeWithMap(text) {
    let normalized = '';
    const map = [];
    for (let index = 0; index < text.length; index++) {
        let part = text[index].normalize('NFD').replace(COMBINING_MARKS, '').toLowerCase();
        if ('ß' === part) {
            part = 'ss';
        }
        for (const char of part) {
            normalized += char;
            map.push(index);
        }
    }

    return { text: normalized, map };
}

export function normalize(text) {
    return normalizeWithMap(text).text;
}

/**
 * Splits a search term into normalised words; an empty or blank term gives no words.
 */
export function searchWords(term) {
    return normalize(term ?? '')
        .split(/\s+/)
        .filter((word) => '' !== word);
}

/**
 * Ranges [start, end) in the original label where any of the words occurs, merged and sorted.
 */
export function highlightRanges(label, words) {
    const { text, map } = normalizeWithMap(label);
    const ranges = [];
    for (const word of words) {
        let from = 0;
        let at;
        while (-1 !== (at = text.indexOf(word, from))) {
            ranges.push([map[at], map[at + word.length - 1] + 1]);
            from = at + word.length;
        }
    }

    ranges.sort((a, b) => a[0] - b[0]);
    const merged = [];
    for (const range of ranges) {
        const last = merged[merged.length - 1];
        if (last && range[0] <= last[1]) {
            last[1] = Math.max(last[1], range[1]);
        } else {
            merged.push([...range]);
        }
    }

    return merged;
}

/**
 * Filters the tree for a term.
 *
 * @param {Array<{id: string, label: string, section: string, children: Array}>} nodes top-level nodes
 * @param {Array<{id: string, label: string}>} sections
 * @param {string} term
 * @returns {{
 *   active: boolean,
 *   visible: Set<string>,
 *   open: Set<string>,
 *   sections: Set<string>,
 *   highlights: Map<string, Array<[number, number]>>,
 *   links: number,
 * }} active is false for an empty term (show everything as before); links counts visible entries without
 *   children
 */
export function filterTree(nodes, sections, term) {
    const words = searchWords(term);
    const result = {
        active: words.length > 0,
        visible: new Set(),
        open: new Set(),
        sections: new Set(),
        highlights: new Map(),
        links: 0,
    };
    if (!result.active) {
        return result;
    }

    const sectionLabels = new Map(
        sections.map((section) => [section.id, normalize(section.label ?? '')]),
    );
    const sectionMatches = new Set(
        sections
            .filter((section) =>
                words.every((word) => sectionLabels.get(section.id).includes(word)),
            )
            .map((section) => section.id),
    );

    const showAll = (node) => {
        result.visible.add(node.id);
        if (node.children.length > 0) {
            result.open.add(node.id);
            node.children.forEach(showAll);
        }
    };

    const walk = (node, path, ancestors) => {
        const own = normalize(node.label);
        const labels = [...path, own];
        const matches =
            words.some((word) => own.includes(word)) &&
            words.every((word) => labels.some((label) => label.includes(word)));

        if (matches) {
            result.highlights.set(node.id, highlightRanges(node.label, words));
            showAll(node);
            for (const ancestor of ancestors) {
                result.visible.add(ancestor.id);
                result.open.add(ancestor.id);
            }
        }

        node.children.forEach((child) => walk(child, labels, [...ancestors, node]));
    };

    for (const node of nodes) {
        if (sectionMatches.has(node.section)) {
            showAll(node);
            continue;
        }
        walk(node, [sectionLabels.get(node.section) ?? ''], []);
    }

    const count = (node) => {
        if (!result.visible.has(node.id)) {
            return;
        }
        if (0 === node.children.length) {
            result.links++;
        }
        node.children.forEach(count);
    };

    for (const node of nodes) {
        count(node);
        if (result.visible.has(node.id)) {
            result.sections.add(node.section);
        }
    }

    return result;
}
