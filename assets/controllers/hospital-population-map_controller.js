import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

const PARTICIPATING_PIN_COLOR = '#1864ab';
const REFERENCE_PIN_COLOR = '#868e96';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
        geoUrl: String,
        tierLabel: { type: String, default: 'Tier' },
        locationLabel: { type: String, default: 'Location' },
        bedsLabel: { type: String, default: 'Beds' },
        allHospitalsLabel: { type: String, default: 'All hospitals' },
        participantsLabel: { type: String, default: 'Participants' },
        coverageLabel: { type: String, default: 'Coverage' },
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
            this.prepareMapContainer();
            this.ensureMapContainerSize();

            this.map = L.map(this.mapContainerTarget, {
                scrollWheelZoom: true,
                attributionControl: true,
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            }).addTo(this.map);

            this.geoLayer = L.geoJSON(geojson, {
                style: (feature) => this.styleForFeature(feature),
                onEachFeature: (feature, layer) => this.bindFeatureTooltip(feature, layer),
            }).addTo(this.map);

            this.renderMarkers();

            if (this.geoLayer.getBounds().isValid()) {
                this.map.fitBounds(this.geoLayer.getBounds(), { padding: [16, 16] });
            } else {
                this.map.setView([50.55, 9.0], 8);
            }

            this.scheduleInvalidateSize();
        } catch (error) {
            this.showMapError(error);
        }
    }

    renderMarkers() {
        const markers = this.payloadValue?.markers ?? [];
        this.markerLayer = L.layerGroup().addTo(this.map);

        markers.forEach((marker) => {
            const pin = L.marker([marker.lat, marker.lng], {
                icon: this.hospitalPinIcon(marker.isParticipating),
            });

            pin.bindTooltip(this.hospitalTooltipContent(marker), { sticky: true });

            this.markerLayer.addLayer(pin);
        });
    }

    hospitalPinIcon(isParticipating) {
        const color = isParticipating ? PARTICIPATING_PIN_COLOR : REFERENCE_PIN_COLOR;

        if (!this.cachedHospitalPinIcons) {
            this.cachedHospitalPinIcons = new Map();
        }

        if (this.cachedHospitalPinIcons.has(color)) {
            return this.cachedHospitalPinIcons.get(color);
        }

        const icon = L.divIcon({
            className: 'hospital-population-map-pin',
            html: `<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="${color}" stroke="#ffffff" stroke-width="1.5" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle fill="#ffffff" cx="12" cy="9" r="2.5"/></svg>`,
            iconSize: [22, 22],
            iconAnchor: [11, 22],
            tooltipAnchor: [0, -18],
        });

        this.cachedHospitalPinIcons.set(color, icon);

        return icon;
    }

    hospitalTooltipContent(marker) {
        const tier = marker.careLevel ?? 'n/a';
        const location = marker.location ?? 'n/a';

        return [
            `<strong>${marker.name}</strong>`,
            `${this.tierLabelValue}: ${tier}`,
            `${this.locationLabelValue}: ${location}`,
            `${this.bedsLabelValue}: ${marker.beds ?? 0}`,
        ].join('<br/>');
    }

    choroplethByKey() {
        const features = this.payloadValue?.choropleth ?? [];
        const byKey = new Map();

        features.forEach((feature) => {
            byKey.set(feature.geoFeatureKey, feature);
        });

        return byKey;
    }

    styleForFeature(feature) {
        const key = feature.properties?.key ?? '';
        const entry = this.choroplethByKey().get(key);

        if (!entry) {
            return {
                fillColor: '#e9ecef',
                weight: 1.5,
                opacity: 1,
                color: '#343a40',
                fillOpacity: 0.25,
            };
        }

        return {
            fillColor: this.colorForCoverage(entry.coverage),
            weight: 1.5,
            opacity: 1,
            color: '#343a40',
            fillOpacity: 0.35,
        };
    }

    bindFeatureTooltip(feature, layer) {
        const key = feature.properties?.key ?? '';
        const entry = this.choroplethByKey().get(key);
        const name = feature.properties?.name ?? entry?.landkreis ?? key;

        if (!entry) {
            layer.bindTooltip(`${name}: n/a`, { sticky: true });
            return;
        }

        layer.bindTooltip(
            `<strong>${name}</strong><br/>${this.allHospitalsLabelValue}: ${entry.population}<br/>${this.participantsLabelValue}: ${entry.participants}<br/>${this.coverageLabelValue}: ${(entry.coverage * 100).toFixed(1)}%`,
            { sticky: true },
        );
    }

    colorForCoverage(coverage) {
        const ratio = Math.max(0, Math.min(1, coverage));
        if (ratio < 0.33) {
            return '#d63939';
        }
        if (ratio < 0.66) {
            return '#f59f00';
        }

        return '#2fb344';
    }

    async loadGeoJson() {
        if (this.geoJsonCache) {
            return this.geoJsonCache;
        }

        const response = await fetch(this.geoUrlValue, { headers: { Accept: 'application/geo+json, application/json' } });
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
            this.markerLayer = null;
        }

        this.cachedHospitalPinIcons = null;
    }

    showMapError(error) {
        if (!this.hasMapContainerTarget) {
            return;
        }

        this.mapContainerTarget.innerHTML = `<div class="alert alert-warning mb-0" role="alert">Map could not be loaded: ${error.message}</div>`;
    }
}
