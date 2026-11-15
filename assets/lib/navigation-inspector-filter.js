/*
 * Filter of the admin navigation tree (#1237). Pure functions without DOM access, tested with `node --test`;
 * the navigation-inspector controller reads the tree from the page and applies the result.
 *
 * A node is { id, text, issue, hidden, onPath, children }: `text` holds what the search looks at (label, id,
 * routes), `issue` marks a deviation (hidden or broken), `onPath` the active path of the checked route.
 *
 * - A node matches when the search text contains the term (case and accents ignored), it is a deviation if
 *   only deviations are wanted, and it is visible unless hidden entries are shown.
 * - With "only the path", a node matches when it is on the path; the other options do not apply then.
 * - A node stays in the tree when it matches or one of its descendants does, so every match keeps its
 *   parents.
 */
import { normalize } from './navigation-filter.js';

/**
 * @param {Array<{id: string, text: string, issue: boolean, hidden: boolean, onPath: boolean, children: Array}>} nodes
 * @param {{query?: string, onlyIssues?: boolean, showHidden?: boolean, onlyPath?: boolean}} options
 * @returns {{keep: Set<string>, shown: number, total: number}}
 */
export function filterInspectorTree(
    nodes,
    { query = '', onlyIssues = false, showHidden = true, onlyPath = false } = {},
) {
    const term = normalize(query.trim());
    const keep = new Set();
    let total = 0;

    const matches = (node) => {
        if (onlyPath) {
            return node.onPath;
        }

        return (
            ('' === term || normalize(node.text).includes(term)) &&
            (!onlyIssues || node.issue) &&
            (showHidden || !node.hidden)
        );
    };

    const visit = (node) => {
        total++;
        const childKept = node.children.map(visit).some(Boolean);
        const kept = matches(node) || childKept;
        if (kept) {
            keep.add(node.id);
        }

        return kept;
    };
    nodes.forEach(visit);

    return { keep, shown: keep.size, total };
}
