const geoJsonCache = new Map();

export async function loadGeoJson(url) {
    if (!url) {
        throw new Error('GeoJSON URL is missing');
    }

    if (geoJsonCache.has(url)) {
        return geoJsonCache.get(url);
    }

    const response = await fetch(url, {
        headers: { Accept: 'application/geo+json, application/json' },
    });
    if (!response.ok) {
        throw new Error(`GeoJSON request failed (${response.status})`);
    }

    const geojson = await response.json();
    geoJsonCache.set(url, geojson);

    return geojson;
}
