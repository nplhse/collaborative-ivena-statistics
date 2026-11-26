/**
 * Month range of the access overview (#1001): the last `count` months, oldest first. `count` 0 keeps every month.
 * The server always sends all monthly sums (one per month), so switching the range needs no request.
 *
 * @template T
 * @param {T[]} months oldest first
 * @param {number} count
 * @returns {T[]}
 */
export function lastMonths(months, count) {
    if (!Array.isArray(months)) {
        return [];
    }

    if (!Number.isInteger(count) || count <= 0 || count >= months.length) {
        return [...months];
    }

    return months.slice(months.length - count);
}
