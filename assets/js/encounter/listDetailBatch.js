/**
 * Collects the encounter ids of the cells that connect in one tick and loads their details with one request
 * per endpoint (#1203). `fetchDetails(url, ids)` resolves to `{ "<id>": "<html>" }`; every waiting cell gets
 * its html, or null when the answer has none or the request failed.
 */
export const MAX_IDS_PER_REQUEST = 100;

export function createDetailBatcher(
    fetchDetails,
    schedule = (callback) => setTimeout(callback, 0),
) {
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
            fetchDetails(url, chunk)
                .then((details) => {
                    for (const id of chunk) {
                        const html = details?.[id];
                        waiting
                            .get(id)
                            .forEach((resolve) => resolve(typeof html === 'string' ? html : null));
                    }
                })
                .catch(() => {
                    for (const id of chunk) {
                        waiting.get(id).forEach((resolve) => resolve(null));
                    }
                });
        }
    }

    return function request(url, id) {
        return new Promise((resolve) => {
            if (!pending.has(url)) {
                pending.set(url, new Map());
                schedule(() => flush(url));
            }
            const waiting = pending.get(url);
            if (!waiting.has(id)) {
                waiting.set(id, []);
            }
            waiting.get(id).push(resolve);
        });
    };
}

export async function fetchDetailsJson(url, ids) {
    const separator = url.includes('?') ? '&' : '?';
    const response = await fetch(
        `${url}${separator}encounters=${ids.map(encodeURIComponent).join(',')}`,
        {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        },
    );
    if (!response.ok) {
        throw new Error(`Encounter details failed (${response.status})`);
    }
    const body = await response.json();

    return body?.details ?? {};
}
