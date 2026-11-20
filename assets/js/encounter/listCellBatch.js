/**
 * Collects the encounter cells that connect in one tick and loads them with one request per endpoint (#1203): the
 * ids and columns of all waiting cells. `fetchCells(url, ids, columns)` resolves to `{ "<id>": { "<column>": "<html>" } }`;
 * every waiting cell gets its html, or null when the answer has none or the request failed.
 */
export const MAX_IDS_PER_REQUEST = 100;

export function createCellBatcher(fetchCells, schedule = (callback) => setTimeout(callback, 0)) {
    const pending = new Map();

    function flush(url) {
        const waiting = pending.get(url);
        pending.delete(url);
        if (!waiting) {
            return;
        }

        const ids = [...waiting.keys()];
        for (let start = 0; start < ids.length; start += MAX_IDS_PER_REQUEST) {
            const chunk = ids.slice(start, start + MAX_IDS_PER_REQUEST);
            const columns = [...new Set(chunk.flatMap((id) => [...waiting.get(id).keys()]))];
            const answer = (cells) => {
                for (const id of chunk) {
                    for (const [column, resolvers] of waiting.get(id)) {
                        const html = cells?.[id]?.[column];
                        resolvers.forEach((resolve) =>
                            resolve(typeof html === 'string' ? html : null),
                        );
                    }
                }
            };
            fetchCells(url, chunk, columns)
                .then(answer)
                .catch(() => answer(null));
        }
    }

    return function request(url, id, column) {
        return new Promise((resolve) => {
            if (!pending.has(url)) {
                pending.set(url, new Map());
                schedule(() => flush(url));
            }
            const byId = pending.get(url);
            if (!byId.has(id)) {
                byId.set(id, new Map());
            }
            const byColumn = byId.get(id);
            if (!byColumn.has(column)) {
                byColumn.set(column, []);
            }
            byColumn.get(column).push(resolve);
        });
    };
}

export async function fetchCellsJson(url, ids, columns) {
    const query = new URLSearchParams({ encounters: ids.join(','), columns: columns.join(',') });
    const separator = url.includes('?') ? '&' : '?';
    const response = await fetch(`${url}${separator}${query}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error(`Encounter cells failed (${response.status})`);
    }
    const body = await response.json();

    return body?.cells ?? {};
}
