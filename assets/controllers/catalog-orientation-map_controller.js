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
            this.geoLayer = L.geoJSON(geojson, {
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

            if (highlightLayer && highlightLayer.getBounds?.().isValid()) {
                this.map.fitBounds(highlightLayer.getBounds(), { padding: [24, 24], maxZoom: 10 });
            } else if (this.geoLayer.getBounds().isValid()) {
                this.map.fitBounds(this.geoLayer.getBounds(), { padding: [16, 16] });
            } else {
                this.map.setView([50.55, 9.0], 8);
            }

            this.scheduleInvalidateSize();
        } catch (error) {
            this.mapContainerTarget.innerHTML = `<div class="text-danger p-3 small">${String(error)}</div>`;
        }
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
    }
}
