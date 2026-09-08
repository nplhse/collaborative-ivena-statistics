import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import intersect from '@turf/intersect';

const MUTED_STYLE = {
    color: '#868e96',
    weight: 1,
    opacity: 0.7,
    fillColor: '#ced4da',
    fillOpacity: 0.25,
};

const HIGHLIGHT_STYLE = {
    color: '#1864ab',
    weight: 2,
    opacity: 1,
    fillColor: '#339af0',
    fillOpacity: 0.55,
};

const ORIGIN_WITH_ISOCHRONE_STYLE = {
    color: '#1864ab',
    weight: 2,
    opacity: 0.85,
    fillColor: '#339af0',
    fillOpacity: 0.12,
};

const DESTINATION_STYLE = {
    color: '#d9480f',
    weight: 2,
    opacity: 1,
    fillColor: '#fd7e14',
    fillOpacity: 0.3,
};

const ALL_AREAS_STYLE = {
    color: '#495057',
    weight: 1,
    opacity: 0.85,
    fillColor: '#74c0fc',
    fillOpacity: 0.35,
};

const ISOCHRONE_OUTSIDE_STYLE = {
    stroke: false,
    fillColor: '#fd7e14',
    fillOpacity: 0.22,
    interactive: false,
};

const ISOCHRONE_INSIDE_STYLE = {
    color: '#d9480f',
    weight: 2,
    opacity: 1,
    fillColor: '#fd7e14',
    fillOpacity: 0.42,
    interactive: true,
};

const ISOCHRONE_OUTLINE_STYLE = {
    color: '#e8590c',
    weight: 2,
    opacity: 0.95,
    dashArray: '8 5',
    lineCap: 'round',
    lineJoin: 'round',
    fill: false,
    interactive: false,
};

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        geoUrl: String,
        highlightKey: { type: String, default: '' },
        showAll: { type: Boolean, default: false },
        markerLat: { type: Number, default: Number.NaN },
        markerLng: { type: Number, default: Number.NaN },
        markerLabel: { type: String, default: '' },
        showRoute: { type: Boolean, default: false },
        destinationHighlightKey: { type: String, default: '' },
        isochrones: { type: Object, default: {} },
        recordedTravelMinutes: { type: Number, default: Number.NaN },
        contextMarkers: { type: Array, default: [] },
    };

    static targets = ['mapContainer'];

    connect() {
        this.geoJsonCache = null;
        this._invalidateRaf = null;
        this._invalidateTimeout = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.boundInvalidateSize = this.invalidateMapSize.bind(this);
        window.addEventListener('resize', this.boundInvalidateSize);
        void this.renderMap(this._renderGeneration).catch((error) => {
            this.showMapError(error);
        });
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        window.removeEventListener('resize', this.boundInvalidateSize);
        this.destroyMap();
    }

    async renderMap(generation) {
        if (!this.hasMapContainerTarget) {
            return;
        }

        try {
            const geojson = await this.loadGeoJson();
            if (generation !== this._renderGeneration) {
                return;
            }

            this.destroyMap();
            this.prepareMapContainer();
            this.ensureMapContainerSize();

            this.map = L.map(this.mapContainerTarget, {
                scrollWheelZoom: true,
                attributionControl: true,
            });
            this.map.setView([50.55, 9.0], 8);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(this.map);

            let highlightLayer = null;
            let destinationLayer = null;
            const features = this.featuresForDisplay(geojson);
            this.geoLayer = L.geoJSON(features, {
                style: (feature) => this.styleForFeature(feature),
                onEachFeature: (feature, layer) => {
                    const name = feature?.properties?.name ?? feature?.properties?.key ?? '';
                    if (name) {
                        layer.bindTooltip(String(name), { sticky: true });
                    }
                    if (this.isHighlighted(feature)) {
                        highlightLayer = layer;
                    }
                    if (this.isDestinationHighlighted(feature)) {
                        destinationLayer = layer;
                    }
                },
            }).addTo(this.map);
            this.highlightLayer = highlightLayer;
            this.destinationLayer = destinationLayer;

            this.renderIsochrones();
            this.renderContextMarkers();
            this.renderDestinationMarker();
            this.fitMapToContents();

            this.scheduleInvalidateSize();
        } catch (error) {
            this.showMapError(error);
        }
    }

    featuresForDisplay(geojson) {
        if (this.showAllValue || !this.highlightKeyValue || !Array.isArray(geojson?.features)) {
            return geojson;
        }

        const keys = new Set([this.highlightKeyValue]);
        if (this.destinationHighlightKeyValue) {
            keys.add(this.destinationHighlightKeyValue);
        }

        return {
            ...geojson,
            features: geojson.features.filter((feature) => keys.has(feature?.properties?.key)),
        };
    }

    renderIsochrones() {
        this.isochroneHighlightLayer = null;
        if (!this.map || !this.hasIsochrones()) {
            return;
        }

        const group = L.featureGroup().addTo(this.map);
        const originFeature = this.originPolygonFeature();
        const features = this.isochronesValue.features;

        for (const feature of features) {
            L.geoJSON(feature, { style: () => ISOCHRONE_OUTSIDE_STYLE }).addTo(group);

            const inside = originFeature ? this.intersectWithOrigin(feature, originFeature) : null;
            if (inside) {
                L.geoJSON(inside, {
                    style: () => ISOCHRONE_INSIDE_STYLE,
                    onEachFeature: (insideFeature, featureLayer) => {
                        const minutes = Math.round((this.isochroneValue(feature) ?? 0) / 60);
                        if (minutes > 0) {
                            featureLayer.bindTooltip(`${minutes} min`, { sticky: true });
                        }
                    },
                }).addTo(group);
            }

            L.geoJSON(feature, { style: () => ISOCHRONE_OUTLINE_STYLE }).addTo(group);
        }

        group.bringToFront();
        this.isochroneHighlightLayer = group;
    }

    originPolygonFeature() {
        const geojson = this.highlightLayer?.toGeoJSON?.();
        if (!geojson) {
            return null;
        }

        if (geojson.type === 'Feature' && geojson.geometry) {
            return geojson;
        }

        const first = geojson.features?.[0];
        if (first?.type === 'Feature' && first.geometry) {
            return first;
        }

        return null;
    }

    intersectWithOrigin(isochroneFeature, originFeature) {
        try {
            return intersect({
                type: 'FeatureCollection',
                features: [
                    { type: 'Feature', properties: {}, geometry: originFeature.geometry },
                    { type: 'Feature', properties: {}, geometry: isochroneFeature.geometry },
                ],
            });
        } catch {
            return null;
        }
    }

    hasIsochrones() {
        return (
            Array.isArray(this.isochronesValue?.features) &&
            this.isochronesValue.features.length > 0
        );
    }

    isochroneValue(feature) {
        const value = feature?.properties?.value;
        if (typeof value === 'number' && Number.isFinite(value)) {
            return value;
        }

        return null;
    }

    renderContextMarkers() {
        if (!this.map) {
            return;
        }

        for (const marker of this.contextMarkersValue) {
            const lat = Number(marker?.lat);
            const lng = Number(marker?.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                continue;
            }

            const pin = L.marker([lat, lng], {
                icon: this.contextHospitalPinIcon(),
                zIndexOffset: -100,
            }).addTo(this.map);

            if (typeof marker.label === 'string' && marker.label !== '') {
                pin.bindTooltip(marker.label, { sticky: true });
            }
        }
    }

    renderDestinationMarker() {
        if (!this.map || !this.hasDestinationMarker()) {
            return;
        }

        const pin = L.marker([this.markerLatValue, this.markerLngValue], {
            icon: this.hospitalPinIcon(),
        }).addTo(this.map);

        if (this.markerLabelValue) {
            pin.bindTooltip(this.markerLabelValue, { sticky: true });
        }
    }

    layerBounds(layer) {
        const bounds = layer?.getBounds?.();

        return bounds?.isValid?.() ? bounds : null;
    }

    fitMapToContents() {
        if (!this.map) {
            return;
        }

        const corners = [];
        const geoBounds = this.layerBounds(this.geoLayer);
        if (geoBounds) {
            corners.push(geoBounds.getSouthWest(), geoBounds.getNorthEast());
        }

        if (this.hasDestinationMarker()) {
            corners.push(L.latLng(this.markerLatValue, this.markerLngValue));
        }

        for (const marker of this.contextMarkersValue) {
            const lat = Number(marker?.lat);
            const lng = Number(marker?.lng);
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                corners.push(L.latLng(lat, lng));
            }
        }

        const isochroneBounds = this.layerBounds(this.isochroneHighlightLayer);
        if (isochroneBounds) {
            corners.push(isochroneBounds.getSouthWest(), isochroneBounds.getNorthEast());
        }

        if (corners.length === 0) {
            return;
        }

        this.map.fitBounds(L.latLngBounds(corners), {
            padding: [28, 28],
            maxZoom: this.hasDestinationMarker() ? 13 : 11,
            animate: false,
        });
    }

    hasDestinationMarker() {
        return Number.isFinite(this.markerLatValue) && Number.isFinite(this.markerLngValue);
    }

    hospitalPinIcon() {
        const fill = this.showRouteValue ? '#d9480f' : '#1864ab';

        return L.divIcon({
            className: 'catalog-orientation-map-pin',
            html: `<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="${fill}" stroke="#ffffff" stroke-width="1.5" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle fill="#ffffff" cx="12" cy="9" r="2.5"/></svg>`,
            iconSize: [26, 26],
            iconAnchor: [13, 26],
            tooltipAnchor: [0, -20],
        });
    }

    contextHospitalPinIcon() {
        return L.divIcon({
            className: 'catalog-orientation-map-pin catalog-orientation-map-pin-context',
            html: '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="#1864ab" stroke="#ffffff" stroke-width="1.5" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle fill="#ffffff" cx="12" cy="9" r="2.5"/></svg>',
            iconSize: [22, 22],
            iconAnchor: [11, 22],
            tooltipAnchor: [0, -18],
        });
    }

    styleForFeature(feature) {
        if (this.isHighlighted(feature)) {
            return this.hasIsochrones() ? ORIGIN_WITH_ISOCHRONE_STYLE : HIGHLIGHT_STYLE;
        }

        if (this.isDestinationHighlighted(feature)) {
            return DESTINATION_STYLE;
        }

        return this.showAllValue ? ALL_AREAS_STYLE : MUTED_STYLE;
    }

    isHighlighted(feature) {
        const key = this.highlightKeyValue;
        if (!key) {
            return false;
        }

        return feature?.properties?.key === key;
    }

    isDestinationHighlighted(feature) {
        const key = this.destinationHighlightKeyValue;
        if (!key) {
            return false;
        }

        return feature?.properties?.key === key;
    }

    async loadGeoJson() {
        if (this.geoJsonCache) {
            return this.geoJsonCache;
        }

        const response = await fetch(this.geoUrlValue, {
            headers: { Accept: 'application/geo+json, application/json' },
        });
        if (!response.ok) {
            throw new Error(`GeoJSON request failed (${response.status})`);
        }

        this.geoJsonCache = await response.json();

        return this.geoJsonCache;
    }

    prepareMapContainer() {
        this.mapContainerTarget.innerHTML = '';
        this.mapContainerTarget.classList.add('case-flow-map-container');
    }

    ensureMapContainerSize() {
        if (!this.hasMapContainerTarget) {
            return;
        }

        const frame =
            this.mapContainerTarget.closest('.catalog-orientation-map-frame') ??
            this.mapContainerTarget;
        let { width, height } = frame.getBoundingClientRect();

        if (width > 0 && height <= 0) {
            height = Math.min(width * 0.75, 420);
        }

        if (width <= 0) {
            width = frame.clientWidth || this.element.clientWidth || 640;
        }

        if (height <= 0) {
            height = 220;
        }

        this.mapContainerTarget.style.width = `${width}px`;
        this.mapContainerTarget.style.height = `${height}px`;
        void this.mapContainerTarget.offsetWidth;
    }

    scheduleInvalidateSize() {
        this.clearScheduledInvalidate();
        this._invalidateRaf = window.requestAnimationFrame(() => {
            this._invalidateRaf = null;
            this.invalidateMapSize();
            this._invalidateTimeout = window.setTimeout(() => {
                this._invalidateTimeout = null;
                this.invalidateMapSize();
            }, 150);
        });
    }

    clearScheduledInvalidate() {
        if (this._invalidateRaf) {
            cancelAnimationFrame(this._invalidateRaf);
            this._invalidateRaf = null;
        }

        if (this._invalidateTimeout) {
            window.clearTimeout(this._invalidateTimeout);
            this._invalidateTimeout = null;
        }
    }

    invalidateMapSize() {
        const map = this.map;
        const container = map?.getContainer?.();
        if (!map || !container?.isConnected) {
            return;
        }

        this.ensureMapContainerSize();
        try {
            map.invalidateSize({ animate: false });
        } catch {
            // Safari can throw if Leaflet panes were already torn down.
        }
    }

    destroyMap() {
        this.clearScheduledInvalidate();
        const map = this.map;
        this.map = null;
        this.geoLayer = null;
        this.highlightLayer = null;
        this.destinationLayer = null;
        this.isochroneHighlightLayer = null;

        if (!map) {
            return;
        }

        try {
            map.remove();
        } catch {
            // Leaflet 1.9 can throw in Safari when removing a map whose layers
            // never fully attached (no view / zero-size container).
        }
    }

    showMapError(error) {
        try {
            this.destroyMap();
        } catch {
            this.map = null;
        }

        if (!this.hasMapContainerTarget) {
            return;
        }

        this.mapContainerTarget.innerHTML = `<div class="text-danger p-3 small">${String(error)}</div>`;
    }
}
