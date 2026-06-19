import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
        geoUrl: String,
    };

    static targets = ['mapContainer', 'modeAbsolute', 'modeRelative'];

    connect() {
        this.mapMode = 'absolute';
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

    setAbsoluteMode() {
        this.mapMode = 'absolute';
        this.syncModeButtons();
        this.updateOverlayStyles();
    }

    setRelativeMode() {
        this.mapMode = 'relative';
        this.syncModeButtons();
        this.updateOverlayStyles();
    }

    syncModeButtons() {
        if (this.hasModeAbsoluteTarget) {
            this.modeAbsoluteTarget.classList.toggle('active', this.mapMode === 'absolute');
        }
        if (this.hasModeRelativeTarget) {
            this.modeRelativeTarget.classList.toggle('active', this.mapMode === 'relative');
        }
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

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(this.map);

            this.geoLayer = L.geoJSON(geojson, {
                style: (feature) => this.styleForFeature(feature),
                onEachFeature: (feature, layer) => this.bindFeatureTooltip(feature, layer),
            }).addTo(this.map);

            if (this.geoLayer.getBounds().isValid()) {
                this.map.fitBounds(this.geoLayer.getBounds(), { padding: [16, 16] });
            } else {
                this.map.setView([50.55, 9.0], 8);
            }

            this.syncModeButtons();
            this.scheduleInvalidateSize();
        } catch (error) {
            this.showMapError(error);
        }
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
        const square = this.mapContainerTarget.closest('.case-flow-map-square');
        if (!square) {
            return;
        }

        const { width, height } = square.getBoundingClientRect();
        if (width > 0 && height > 0) {
            this.mapContainerTarget.style.width = `${width}px`;
            this.mapContainerTarget.style.height = `${height}px`;
        }
    }

    valueByKey() {
        const features = this.payloadValue?.mapFeatures ?? [];
        const valueByKey = new Map();

        features.forEach((feature) => {
            valueByKey.set(feature.geoKey, {
                value: this.mapMode === 'relative' ? feature.sharePercent : feature.caseCount,
                suppressed: feature.suppressed,
                originName: feature.originName,
                caseCount: feature.caseCount,
                sharePercent: feature.sharePercent,
            });
        });

        return valueByKey;
    }

    maxVisibleValue(valueByKey) {
        const values = [...valueByKey.values()]
            .filter((entry) => !entry.suppressed && entry.value > 0)
            .map((entry) => entry.value);

        return values.length > 0 ? Math.max(...values) : 1;
    }

    styleForFeature(feature) {
        const key = feature.properties?.key ?? '';
        const entry = this.valueByKey().get(key);
        const maxValue = this.maxVisibleValue(this.valueByKey());
        const suppressed = !entry || entry.suppressed || entry.value <= 0;

        return {
            fillColor: suppressed ? '#ced4da' : this.colorForValue(entry.value, maxValue),
            weight: 1.5,
            opacity: 1,
            color: '#343a40',
            fillOpacity: suppressed ? 0.25 : 0.55,
        };
    }

    bindFeatureTooltip(feature, layer) {
        const key = feature.properties?.key ?? '';
        const entry = this.valueByKey().get(key);
        const name = feature.properties?.name ?? key;

        if (!entry) {
            layer.bindTooltip(`${name}: n/a`, { sticky: true });
            return;
        }

        if (entry.suppressed) {
            layer.bindTooltip(`${name}: suppressed (n &lt; 10)`, { sticky: true });
            return;
        }

        layer.bindTooltip(
            `<strong>${name}</strong><br/>Cases: ${entry.caseCount}<br/>Share: ${entry.sharePercent}%`,
            { sticky: true },
        );
    }

    updateOverlayStyles() {
        if (!this.geoLayer) {
            return;
        }

        this.geoLayer.eachLayer((layer) => {
            const feature = layer.feature;
            if (!feature) {
                return;
            }
            layer.setStyle(this.styleForFeature(feature));
            layer.unbindTooltip();
            this.bindFeatureTooltip(feature, layer);
        });
    }

    colorForValue(value, maxValue) {
        const ratio = maxValue > 0 ? value / maxValue : 0;
        const hue = 210;
        const lightness = 88 - ratio * 42;
        return `hsl(${hue}, 72%, ${lightness}%)`;
    }

    scheduleInvalidateSize() {
        window.requestAnimationFrame(() => {
            this.invalidateMapSize();
            window.setTimeout(() => this.invalidateMapSize(), 150);
        });
    }

    invalidateMapSize() {
        if (this.map) {
            this.map.invalidateSize({ animate: false });
        }
    }

    destroyMap() {
        if (this.map) {
            this.map.remove();
            this.map = null;
            this.geoLayer = null;
        }
    }

    showMapError(error) {
        if (!this.hasMapContainerTarget) {
            return;
        }

        this.mapContainerTarget.innerHTML = `<div class="alert alert-warning mb-0" role="alert">Map could not be loaded: ${error.message}</div>`;
    }
}
