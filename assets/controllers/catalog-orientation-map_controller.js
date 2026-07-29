import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

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

const ALL_AREAS_STYLE = {
    color: '#495057',
    weight: 1,
    opacity: 0.85,
    fillColor: '#74c0fc',
    fillOpacity: 0.35,
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
    };

    static targets = ['mapContainer'];

    connect() {
        this.geoJsonCache = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.boundInvalidateSize = this.invalidateMapSize.bind(this);
        window.addEventListener('resize', this.boundInvalidateSize);
        void this.renderMap(this._renderGeneration);
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
            this.mapContainerTarget.innerHTML = '';
            this.mapContainerTarget.classList.add('case-flow-map-container');

            this.map = L.map(this.mapContainerTarget, {
                scrollWheelZoom: true,
                attributionControl: true,
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(this.map);

            let highlightLayer = null;
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
                },
            }).addTo(this.map);
            this.highlightLayer = highlightLayer;

            if (highlightLayer && highlightLayer.getBounds?.().isValid()) {
                this.map.fitBounds(highlightLayer.getBounds(), { padding: [24, 24], maxZoom: 11 });
            } else if (this.geoLayer.getBounds().isValid()) {
                this.map.fitBounds(this.geoLayer.getBounds(), { padding: [16, 16] });
            } else {
                this.map.setView([50.55, 9.0], 8);
            }

            this.renderMarker();

            this.scheduleInvalidateSize();
        } catch (error) {
            this.mapContainerTarget.innerHTML = `<div class="text-danger p-3 small">${String(error)}</div>`;
        }
    }

    featuresForDisplay(geojson) {
        if (this.showAllValue || !this.highlightKeyValue || !Array.isArray(geojson?.features)) {
            return geojson;
        }

        return {
            ...geojson,
            features: geojson.features.filter((feature) => this.isHighlighted(feature)),
        };
    }

    renderMarker() {
        if (!Number.isFinite(this.markerLatValue) || !Number.isFinite(this.markerLngValue)) {
            return;
        }

        const pin = L.marker([this.markerLatValue, this.markerLngValue], {
            icon: this.hospitalPinIcon(),
        }).addTo(this.map);

        if (this.markerLabelValue) {
            pin.bindTooltip(this.markerLabelValue, { sticky: true });
        }

        const markerLatLng = pin.getLatLng();
        const focusBounds = this.highlightLayer?.getBounds?.().isValid()
            ? this.highlightLayer.getBounds()
            : this.geoLayer?.getBounds?.().isValid()
              ? this.geoLayer.getBounds()
              : null;

        if (focusBounds) {
            this.map.fitBounds(focusBounds.extend(markerLatLng), {
                padding: [28, 28],
                maxZoom: 13,
            });
        } else {
            this.map.setView(markerLatLng, 12);
        }
    }

    hospitalPinIcon() {
        return L.divIcon({
            className: 'catalog-orientation-map-pin',
            html: '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="#1864ab" stroke="#ffffff" stroke-width="1.5" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle fill="#ffffff" cx="12" cy="9" r="2.5"/></svg>',
            iconSize: [26, 26],
            iconAnchor: [13, 26],
            tooltipAnchor: [0, -20],
        });
    }

    styleForFeature(feature) {
        if (this.isHighlighted(feature)) {
            return HIGHLIGHT_STYLE;
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

    scheduleInvalidateSize() {
        requestAnimationFrame(() => this.invalidateMapSize());
        window.setTimeout(() => this.invalidateMapSize(), 120);
    }

    invalidateMapSize() {
        this.map?.invalidateSize(false);
    }

    destroyMap() {
        if (this.map) {
            this.map.remove();
            this.map = null;
        }
        this.geoLayer = null;
        this.highlightLayer = null;
    }
}
