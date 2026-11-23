/** The browser revalidates through the ETag, so a saved boundary shows on the next render. */
export async function loadGeoJson(url) {
    if (!url) {
        throw new Error('GeoJSON URL is missing');
    }

    const response = await fetch(url, {
        cache: 'no-cache',
        headers: { Accept: 'application/geo+json, application/json' },
    });
    if (!response.ok) {
        throw new Error(`GeoJSON request failed (${response.status})`);
    }

    return response.json();
}

/** Credits the sources of the shown boundaries in the Leaflet attribution. */
export function addBoundaryAttributions(map, features) {
    const list = Array.isArray(features) ? features : (features?.features ?? []);
    const attributions = new Set(
        list.flatMap((feature) => feature?.properties?.attributions ?? []),
    );
    attributions.forEach((attribution) => {
        const text = document.createElement('span');
        text.textContent = attribution;
        map.attributionControl?.addAttribution(text.innerHTML);
    });
}
