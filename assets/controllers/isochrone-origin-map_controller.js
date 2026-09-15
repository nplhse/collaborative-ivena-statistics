import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import {
    createLeafletMap,
    destroyLeafletMap,
    ensureContainerSize,
    invalidateMapSize,
    layerBounds,
    prepareMapContainer,
    scheduleInvalidateSize,
} from '../js/geo-map/createMap.js';
import { hospitalPinIcon } from '../js/geo-map/hospitalPin.js';
import {
    asFeature,
    buildIsochroneRings,
    isochroneStyle,
    isochroneTooltip,
} from '../js/geo-map/isochroneRings.js';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
        casesLabel: { type: String, default: 'Allocations' },
        shareLabel: { type: String, default: 'Share' },
    };

    static targets = ['mapContainer'];

    connect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.boundInvalidateSize = () => {
            invalidateMapSize(this.map, () => this.fitMapToContents());
        };
        window.addEventListener('resize', this.boundInvalidateSize);
        this.renderMap(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        window.removeEventListener('resize', this.boundInvalidateSize);
        this.destroyMap();
    }

    renderMap(generation) {
        if (!this.hasMapContainerTarget) {
            return;
        }

        this.destroyMap();
        prepareMapContainer(this.mapContainerTarget);
        ensureContainerSize(this.mapContainerTarget, '.isochrone-origin-map-frame');

        this.map = createLeafletMap(this.mapContainerTarget, { scrollWheelZoom: false });
        const rings = buildIsochroneRings(this.payloadValue?.bands);
        this.ringLayer = L.layerGroup().addTo(this.map);

        for (const ring of rings) {
            if (ring.count <= 0) {
                continue;
            }

            const layer = L.geoJSON(ring.feature, {
                style: () => isochroneStyle(ring),
            });
            layer.bindTooltip(isochroneTooltip(ring, this.casesLabelValue, this.shareLabelValue), {
                sticky: true,
            });
            this.ringLayer.addLayer(layer);
        }

        this.renderHospitalPin();
        this.fitMapToContents();
        scheduleInvalidateSize(this.map, () => this.fitMapToContents());

        if (generation !== this._renderGeneration) {
            this.destroyMap();
        }
    }

    renderHospitalPin() {
        const hospital = this.payloadValue?.hospital;
        const lat = Number(hospital?.lat);
        const lng = Number(hospital?.lng);
        if (!this.map || !Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }

        const pin = L.marker([lat, lng], {
            icon: hospitalPinIcon({ className: 'isochrone-origin-map-pin' }),
        }).addTo(this.map);
        if (typeof hospital.name === 'string' && hospital.name !== '') {
            pin.bindTooltip(hospital.name, { sticky: true });
        }
    }

    fitMapToContents() {
        if (!this.map) {
            return;
        }

        const bounds = this.largestPopulatedIsochroneBounds() ?? layerBounds(this.ringLayer);
        if (bounds) {
            this.map.fitBounds(bounds, { padding: [28, 28], animate: false });
            return;
        }

        const hospital = this.payloadValue?.hospital;
        const lat = Number(hospital?.lat);
        const lng = Number(hospital?.lng);
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            this.map.setView([lat, lng], 9);
        }
    }

    largestPopulatedIsochroneBounds() {
        const bands = Array.isArray(this.payloadValue?.bands) ? this.payloadValue.bands : [];
        let largest = null;
        for (const band of bands) {
            if (!band?.geometry || Number(band.count) <= 0) {
                continue;
            }
            if (largest === null || Number(band.minutes) > Number(largest.minutes)) {
                largest = band;
            }
        }

        if (largest === null) {
            return null;
        }

        return layerBounds(L.geoJSON(asFeature(largest.geometry, {})));
    }

    destroyMap() {
        destroyLeafletMap(this.map);
        this.map = null;
        this.ringLayer = null;
    }
}
