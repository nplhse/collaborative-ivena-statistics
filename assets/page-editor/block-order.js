// Order of page content blocks in the editor (#967). Mirrors PageContentBlockOrder::sortKeys() on the server.

function toPosition(value) {
    if ('number' === typeof value) {
        return Number.isInteger(value) && value >= 0 ? value : null;
    }

    return 'string' === typeof value && /^\d+$/.test(value) ? Number(value) : null;
}

/**
 * Returns `items` ordered by `positions` (same index). Items without a valid position keep their order after
 * the positioned ones.
 */
export function orderByPositions(items, positions) {
    return items
        .map((item, index) => ({ item, index, position: toPosition(positions[index]) ?? Infinity }))
        .sort((a, b) => a.position - b.position || a.index - b.index)
        .map((entry) => entry.item);
}
