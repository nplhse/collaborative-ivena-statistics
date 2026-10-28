import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const OSM_TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const OSM_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
const DEFAULT_MAX_ZOOM = 18;

/**
 * Tile settings from <meta name="map-tiles"> (MAP_TILE_URL in the layout).
 * An empty URL means no basemap: the map shows only its own layers. When the tile server
 * cannot be reached, createLeafletMap() drops the basemap the same way.
 * Without the meta tag (e.g. isolated tests) the OpenStreetMap defaults apply.
 */
export function mapTileSettings(doc = document) {
    const meta = doc.querySelector('meta[name="map-tiles"]');
    if (!meta) {
        return { url: OSM_TILE_URL, attribution: OSM_ATTRIBUTION, maxZoom: DEFAULT_MAX_ZOOM };
    }

    const maxZoom = Number.parseInt(meta.dataset.maxZoom ?? '', 10);

    return {
        url: (meta.getAttribute('content') ?? '').trim(),
        attribution: meta.dataset.attribution ?? '',
        maxZoom: Number.isFinite(maxZoom) && maxZoom > 0 ? maxZoom : DEFAULT_MAX_ZOOM,
    };
}

// Failed tile requests without a single loaded tile before the basemap is dropped.
const TILE_FAILURE_LIMIT = 4;

// Set once tiles failed on this page (kept across Turbo visits): later maps skip the basemap.
let tilesUnavailable = false;

export function createLeafletMap(container, { scrollWheelZoom = true } = {}) {
    const tiles = mapTileSettings();
    const map = L.map(container, {
        scrollWheelZoom,
        attributionControl: true,
        maxZoom: tiles.maxZoom,
    });

    if (tiles.url === '' || tilesUnavailable) {
        container.classList.add('geo-map--no-basemap');
    } else {
        addTileLayerWithFallback(map, container, tiles);
    }

    return map;
}

/**
 * Adds the tile layer and drops it when the tile server is unreachable (e.g. offline demo):
 * after TILE_FAILURE_LIMIT errors without any loaded tile the map keeps its own layers
 * (districts, pins, isochrones) on a neutral background and stops requesting tiles.
 */
function addTileLayerWithFallback(map, container, tiles) {
    const layer = L.tileLayer(tiles.url, {
        maxZoom: tiles.maxZoom,
        attribution: tiles.attribution,
    });
    let loaded = 0;
    let failed = 0;

    layer.on('tileload', () => {
        loaded += 1;
    });
    layer.on('tileerror', () => {
        failed += 1;
        if (loaded > 0 || failed < TILE_FAILURE_LIMIT || !map.hasLayer(layer)) {
            return;
        }

        tilesUnavailable = true;
        map.removeLayer(layer);
        container.classList.add('geo-map--no-basemap');
    });

    layer.addTo(map);
}

export function destroyLeafletMap(map) {
    if (!map) {
        return;
    }

    try {
        map.remove();
    } catch {
        // Leaflet 1.9 can throw in Safari when panes never fully attached.
    }
}

export function prepareMapContainer(container) {
    container.innerHTML = '';
    container.classList.add('case-flow-map-container');
}

export function ensureContainerSize(container, frameSelector) {
    if (!container) {
        return;
    }

    const frame =
        (frameSelector ? container.closest(frameSelector) : null) ??
        container.parentElement ??
        container;
    let { width, height } = frame.getBoundingClientRect();

    if (width > 0 && height > 0) {
        container.style.width = `${width}px`;
        container.style.height = `${height}px`;
        return;
    }

    if (width <= 0) {
        width = frame.clientWidth || container.clientWidth || 640;
    }

    if (height <= 0) {
        height = Math.max(220, Math.round(width * 0.75));
    }

    container.style.width = `${width}px`;
    container.style.height = `${height}px`;
}

export function scheduleInvalidateSize(map, onInvalidate) {
    window.requestAnimationFrame(() => {
        invalidateMapSize(map, onInvalidate);
        window.setTimeout(() => invalidateMapSize(map, onInvalidate), 150);
    });
}

export function invalidateMapSize(map, onInvalidate) {
    const container = map?.getContainer?.();
    if (!map || !container?.isConnected) {
        return;
    }

    try {
        map.invalidateSize({ animate: false });
    } catch {
        return;
    }

    onInvalidate?.();
}

export function layerBounds(layer) {
    const bounds = layer?.getBounds?.();

    return bounds?.isValid?.() ? bounds : null;
}

export function boundsCenteredOn(bounds, center) {
    if (!bounds?.isValid?.()) {
        return null;
    }

    const lat = Number(center?.lat);
    const lng = Number(center?.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return bounds;
    }

    const southWest = bounds.getSouthWest();
    const northEast = bounds.getNorthEast();
    const latDelta = Math.max(Math.abs(northEast.lat - lat), Math.abs(lat - southWest.lat));
    const lngDelta = Math.max(Math.abs(northEast.lng - lng), Math.abs(lng - southWest.lng));

    if (latDelta === 0 && lngDelta === 0) {
        return bounds;
    }

    return L.latLngBounds([lat - latDelta, lng - lngDelta], [lat + latDelta, lng + lngDelta]);
}
