import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const OSM_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';

export function createLeafletMap(container, { scrollWheelZoom = true } = {}) {
    const map = L.map(container, {
        scrollWheelZoom,
        attributionControl: true,
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 18,
        attribution: OSM_ATTRIBUTION,
    }).addTo(map);

    return map;
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
