import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import difference from '@turf/difference';

const HEAT_GREEN = [47, 158, 68];
const HEAT_YELLOW = [250, 176, 5];
const HEAT_RED = [224, 49, 49];
const FILL_OPACITY = 0.25;
const STROKE_OPACITY = 0.9;
const STROKE_WEIGHT = 2;

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
        this.boundInvalidateSize = this.invalidateMapSize.bind(this);
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
        this.prepareMapContainer();
        this.ensureMapContainerSize();

        this.map = L.map(this.mapContainerTarget, {
            scrollWheelZoom: false,
            attributionControl: true,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 18,
            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        }).addTo(this.map);

        const rings = this.buildRings();
        this.ringLayer = L.layerGroup().addTo(this.map);

        for (const ring of rings) {
            if (ring.count <= 0) {
                continue;
            }

            const layer = L.geoJSON(ring.feature, {
                style: () => this.styleForRing(ring),
            });
            layer.bindTooltip(this.tooltipContent(ring), { sticky: true });
            this.ringLayer.addLayer(layer);
        }

        this.renderHospitalPin();
        this.fitMapToContents();
        this.scheduleInvalidateSize();

        if (generation !== this._renderGeneration) {
            this.destroyMap();
        }
    }

    buildRings() {
        const bands = Array.isArray(this.payloadValue?.bands) ? [...this.payloadValue.bands] : [];
        bands.sort((left, right) => left.minutes - right.minutes);

        const rings = [];
        let previousGeometry = null;

        for (const band of bands) {
            if (!band?.geometry) {
                continue;
            }

            const outer = this.asFeature(band.geometry, band);
            let ringFeature = outer;

            if (previousGeometry) {
                const differenced = this.safeDifference(
                    outer,
                    this.asFeature(previousGeometry, {}),
                );
                if (differenced?.geometry) {
                    ringFeature = differenced;
                }
            }

            rings.push({
                feature: ringFeature,
                intensity: Number(band.intensity) || 0,
                count: Number(band.count) || 0,
                share: Number(band.share) || 0,
                label: typeof band.label === 'string' ? band.label : `${band.minutes} min`,
            });

            previousGeometry = band.geometry;
        }

        return rings.reverse();
    }

    asFeature(geometry, properties) {
        return {
            type: 'Feature',
            properties: properties ?? {},
            geometry,
        };
    }

    safeDifference(outer, inner) {
        try {
            return difference(outer, inner);
        } catch {
            return null;
        }
    }

    styleForRing(ring) {
        const fillColor = this.heatColor(Math.min(1, Math.max(0, ring.intensity)));

        return {
            color: fillColor,
            weight: STROKE_WEIGHT,
            opacity: STROKE_OPACITY,
            fillColor,
            fillOpacity: FILL_OPACITY,
            interactive: true,
        };
    }

    heatColor(t) {
        if (t < 0.5) {
            return this.mixColor(HEAT_GREEN, HEAT_YELLOW, t * 2);
        }

        return this.mixColor(HEAT_YELLOW, HEAT_RED, (t - 0.5) * 2);
    }

    mixColor(from, to, t) {
        const red = Math.round(from[0] + (to[0] - from[0]) * t);
        const green = Math.round(from[1] + (to[1] - from[1]) * t);
        const blue = Math.round(from[2] + (to[2] - from[2]) * t);

        return `rgb(${red}, ${green}, ${blue})`;
    }

    tooltipContent(ring) {
        const sharePercent = (ring.share * 100).toFixed(1);

        return [
            `<strong>${this.escapeHtml(ring.label)}</strong>`,
            `${this.escapeHtml(this.casesLabelValue)}: ${ring.count}`,
            `${this.escapeHtml(this.shareLabelValue)}: ${sharePercent}%`,
        ].join('<br/>');
    }

    escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;');
    }

    renderHospitalPin() {
        const hospital = this.payloadValue?.hospital;
        const lat = Number(hospital?.lat);
        const lng = Number(hospital?.lng);
        if (!this.map || !Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }

        const pin = L.marker([lat, lng], { icon: this.hospitalPinIcon() }).addTo(this.map);
        if (typeof hospital.name === 'string' && hospital.name !== '') {
            pin.bindTooltip(hospital.name, { sticky: true });
        }
    }

    hospitalPinIcon() {
        return L.divIcon({
            className: 'isochrone-origin-map-pin',
            html: '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="#343a40" stroke="#ffffff" stroke-width="1.5" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle fill="#ffffff" cx="12" cy="9" r="2.5"/></svg>',
            iconSize: [24, 24],
            iconAnchor: [12, 24],
            tooltipAnchor: [0, -18],
        });
    }

    fitMapToContents() {
        if (!this.map) {
            return;
        }

        const bounds = this.largestPopulatedIsochroneBounds() ?? this.layerBounds(this.ringLayer);
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

        return this.layerBounds(L.geoJSON(this.asFeature(largest.geometry, {})));
    }

    layerBounds(layer) {
        const bounds = layer?.getBounds?.();

        return bounds?.isValid?.() ? bounds : null;
    }

    prepareMapContainer() {
        this.mapContainerTarget.innerHTML = '';
        this.mapContainerTarget.classList.add('case-flow-map-container');
    }

    ensureMapContainerSize() {
        if (!this.hasMapContainerTarget) {
            return;
        }

        const rect = this.mapContainerTarget.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) {
            return;
        }

        this.mapContainerTarget.style.minHeight = '500px';
    }

    scheduleInvalidateSize() {
        requestAnimationFrame(() => this.invalidateMapSize());
        window.setTimeout(() => this.invalidateMapSize(), 150);
    }

    invalidateMapSize() {
        if (!this.map) {
            return;
        }

        this.map.invalidateSize({ animate: false });
        this.fitMapToContents();
    }

    destroyMap() {
        if (this.map) {
            this.map.remove();
            this.map = null;
        }

        this.ringLayer = null;
    }
}
