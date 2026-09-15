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
import { loadGeoJson } from '../js/geo-map/loadGeoJson.js';
import {
    choroplethStyleForFeature,
    choroplethTooltip,
    choroplethValueByKey,
    syncChoroplethLabels,
} from '../js/geo-map/choropleth.js';
import {
    destinationPinColor,
    destinationPinSize,
    hospitalPinIcon,
    outflowPinColor,
} from '../js/geo-map/hospitalPin.js';
import {
    buildIsochroneRings,
    isochroneStyle,
    isochroneTooltip,
    travelBandIdFromMinutes,
} from '../js/geo-map/isochroneRings.js';
import { escapeHtml } from '../js/geo-map/escapeHtml.js';

const DEFAULT_CENTER = [50.55, 9.0];
const DEFAULT_ZOOM = 8;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
        geoUrl: { type: String, default: '' },
        casesLabel: { type: String, default: 'Allocations' },
        shareLabel: { type: String, default: 'Share' },
        insideLabel: { type: String, default: 'Inside dispatch area' },
        outsideLabel: { type: String, default: 'Outside dispatch area' },
        frameSelector: {
            type: String,
            default: '.geo-map-frame, .case-flow-map-square, .isochrone-origin-map-frame',
        },
        scrollWheelZoom: { type: Boolean, default: true },
    };

    static targets = [
        'mapContainer',
        'expandMapContainer',
        'expandOverlay',
        'modeAbsolute',
        'modeRelative',
        'expandModeAbsolute',
        'expandModeRelative',
        'layerToggle',
        'absoluteLegend',
        'relativeLegend',
    ];

    connect() {
        this.mapMode = 'relative';
        this.expandedLayerOverrides = null;
        this.compactMap = null;
        this.expandMap = null;
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.boundInvalidateSize = this.onWindowResize.bind(this);
        this.boundKeydown = this.onKeydown.bind(this);
        window.addEventListener('resize', this.boundInvalidateSize);
        window.addEventListener('keydown', this.boundKeydown);
        void this.renderCompact(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        window.removeEventListener('resize', this.boundInvalidateSize);
        window.removeEventListener('keydown', this.boundKeydown);
        this.closeExpand({ skipRender: true });
        this.destroyCompact();
    }

    setAbsoluteMode() {
        this.mapMode = 'absolute';
        this.syncModeButtons();
        this.refreshChoroplethStyles(this.compactMap);
        this.refreshChoroplethStyles(this.expandMap);
    }

    setRelativeMode() {
        this.mapMode = 'relative';
        this.syncModeButtons();
        this.refreshChoroplethStyles(this.compactMap);
        this.refreshChoroplethStyles(this.expandMap);
    }

    toggleLayer(event) {
        const layer = event.currentTarget?.dataset?.layer;
        if (!layer) {
            return;
        }

        const enabled = this.enabledLayers(true);
        if (event.currentTarget.checked) {
            enabled.add(layer);
        } else {
            enabled.delete(layer);
        }
        this.expandedLayerOverrides = enabled;
        void this.renderExpand(this._renderGeneration);
    }

    expand() {
        if (!this.hasExpandOverlayTarget || !this.hasExpandMapContainerTarget) {
            return;
        }

        this.expandedLayerOverrides = null;
        this.expandOverlayTarget.classList.add('is-open');
        this.expandOverlayTarget.setAttribute('aria-hidden', 'false');
        document.body.classList.add('geo-map-expand-open');
        this.syncLayerToggles();
        void this.renderExpand(this._renderGeneration);
    }

    collapse() {
        this.closeExpand();
    }

    onKeydown(event) {
        if (event.key === 'Escape' && this.isExpanded()) {
            this.closeExpand();
        }
    }

    onWindowResize() {
        if (this.compactMap) {
            ensureContainerSize(this.mapContainerTarget, this.frameSelectorValue);
            invalidateMapSize(this.compactMap.map);
        }
        if (this.expandMap && this.hasExpandMapContainerTarget) {
            ensureContainerSize(this.expandMapContainerTarget, '.geo-map-expand-canvas');
            invalidateMapSize(this.expandMap.map, () => this.fitMap(this.expandMap, true));
        }
    }

    async renderCompact(generation) {
        if (!this.hasMapContainerTarget) {
            return;
        }

        await this.renderInto(this.mapContainerTarget, false, generation);
    }

    async renderExpand(generation) {
        if (!this.hasExpandMapContainerTarget || !this.isExpanded()) {
            return;
        }

        await this.renderInto(this.expandMapContainerTarget, true, generation);
    }

    async renderInto(container, expanded, generation) {
        try {
            const geojson = this.needsChoropleth(expanded) ? await this.loadBoundaries() : null;
            if (generation !== this._renderGeneration) {
                return;
            }

            if (expanded) {
                this.destroyExpand();
            } else {
                this.destroyCompact();
            }

            prepareMapContainer(container);
            ensureContainerSize(
                container,
                expanded ? '.geo-map-expand-canvas' : this.frameSelectorValue,
            );

            const map = createLeafletMap(container, {
                scrollWheelZoom: expanded ? true : this.scrollWheelZoomValue,
            });
            const context = {
                map,
                geoLayer: null,
                pinLayer: null,
                ringLayer: null,
                labelLayer: null,
            };

            if (geojson && this.layerEnabled('originChoropleth', expanded)) {
                const values = this.choroplethValues();
                context.geoLayer = L.geoJSON(geojson, {
                    style: (feature) =>
                        choroplethStyleForFeature(
                            feature,
                            values,
                            this.selectedDispatchAreaId(),
                            this.selectedOriginDispatchAreaId(),
                        ),
                    onEachFeature: (feature, layer) => {
                        layer.bindTooltip(
                            choroplethTooltip(
                                feature,
                                values,
                                this.casesLabelValue,
                                this.shareLabelValue,
                            ),
                            { sticky: true },
                        );
                        if (this.segmentSelectionEnabled()) {
                            layer.on('click', () => {
                                const entry = values.get(feature.properties?.key ?? '');
                                const dispatchAreaId = Number(entry?.dispatchAreaId);
                                if (!Number.isFinite(dispatchAreaId) || dispatchAreaId <= 0) {
                                    return;
                                }
                                this.visitSegment(`origin:${dispatchAreaId}`);
                            });
                        }
                    },
                }).addTo(map);
                context.labelLayer = syncChoroplethLabels(map, context.geoLayer, values, null, {
                    expanded,
                });
            }

            if (this.layerEnabled('isochroneBands', expanded)) {
                this.renderIsochrones(context);
            }

            if (this.layerEnabled('destinationHospitals', expanded)) {
                this.renderDestinationPins(context);
            }

            if (this.layerEnabled('hospitalPin', expanded)) {
                this.renderHospitalPin(context);
            }

            this.fitMap(context, expanded);
            this.syncModeButtons();
            scheduleInvalidateSize(map, () => this.fitMap(context, expanded));

            if (expanded) {
                this.expandMap = context;
            } else {
                this.compactMap = context;
            }

            if (generation !== this._renderGeneration) {
                destroyLeafletMap(map);
            }
        } catch (error) {
            this.showMapError(container, error);
        }
    }

    renderIsochrones(context) {
        const rings = buildIsochroneRings(this.isochroneBands());
        context.ringLayer = L.layerGroup().addTo(context.map);

        for (const ring of rings) {
            if (ring.count <= 0) {
                continue;
            }

            const layer = L.geoJSON(ring.feature, {
                style: () => isochroneStyle(ring, this.isSelectedTravelBand(ring.minutes)),
            });
            layer.bindTooltip(isochroneTooltip(ring, this.casesLabelValue, this.shareLabelValue), {
                sticky: true,
            });
            if (this.segmentSelectionEnabled()) {
                layer.on('click', () => {
                    const bandId = travelBandIdFromMinutes(ring.minutes);
                    if (!bandId) {
                        return;
                    }
                    this.visitSegment(`travel:${bandId}`);
                });
            }
            context.ringLayer.addLayer(layer);
        }
    }

    renderDestinationPins(context) {
        const hospitals = this.payloadValue?.destinationHospitals ?? [];
        const visible = hospitals.filter((hospital) => !hospital.suppressed);
        const maxCount = visible.reduce(
            (max, hospital) => Math.max(max, Number(hospital.caseCount) || 0),
            0,
        );
        context.pinLayer = L.layerGroup().addTo(context.map);

        visible.forEach((hospital) => {
            const lat = Number(hospital.lat);
            const lng = Number(hospital.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return;
            }

            const pin = L.marker([lat, lng], {
                icon: hospitalPinIcon({
                    color:
                        hospital.insideSelectedArea === false
                            ? outflowPinColor()
                            : destinationPinColor(hospital.tierCode),
                    className: 'geo-map-pin',
                    size: destinationPinSize(hospital.caseCount, maxCount),
                }),
            });
            pin.bindTooltip(this.destinationTooltip(hospital), { sticky: true });
            context.pinLayer.addLayer(pin);
        });
    }

    renderHospitalPin(context) {
        const hospital = this.payloadValue?.hospital;
        const lat = Number(hospital?.lat);
        const lng = Number(hospital?.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return;
        }

        if (!context.pinLayer) {
            context.pinLayer = L.layerGroup().addTo(context.map);
        }

        const pin = L.marker([lat, lng], {
            icon: hospitalPinIcon({ className: 'geo-map-pin isochrone-origin-map-pin' }),
        });
        if (typeof hospital.name === 'string' && hospital.name !== '') {
            pin.bindTooltip(escapeHtml(hospital.name), { sticky: true });
        }
        context.pinLayer.addLayer(pin);
    }

    destinationTooltip(hospital) {
        const lines = [`<strong>${escapeHtml(hospital.name ?? '')}</strong>`];
        lines.push(`${escapeHtml(this.casesLabelValue)}: ${hospital.caseCount}`);
        if (typeof hospital.sharePercent === 'number') {
            lines.push(`${escapeHtml(this.shareLabelValue)}: ${hospital.sharePercent}%`);
        }
        if (hospital.insideSelectedArea === false) {
            lines.push(escapeHtml(this.outsideLabelValue));
        } else if (this.selectedDispatchAreaId() !== null) {
            lines.push(escapeHtml(this.insideLabelValue));
        }

        return lines.join('<br/>');
    }

    selectedDispatchAreaId() {
        const raw = this.payloadValue?.selectedDispatchAreaId;
        const id = Number(raw);
        if (!Number.isFinite(id) || id <= 0) {
            return null;
        }

        return id;
    }

    selectedOriginDispatchAreaId() {
        const selected = this.payloadValue?.selectedSegment;
        if (!selected || selected.type !== 'origin_area') {
            return null;
        }

        const id = Number(selected.id);
        if (!Number.isFinite(id) || id <= 0) {
            return null;
        }

        return id;
    }

    isSelectedTravelBand(minutes) {
        const selected = this.payloadValue?.selectedSegment;
        if (!selected || selected.type !== 'travel_time_band') {
            return false;
        }

        return travelBandIdFromMinutes(minutes) === selected.id;
    }

    segmentSelectionEnabled() {
        return this.payloadValue?.segmentSelectionEnabled === true;
    }

    visitSegment(token) {
        const url = new URL(window.location.href);
        url.searchParams.set('geo_segment', token);
        if (!url.searchParams.get('geo_profile')) {
            url.searchParams.set('geo_profile', 'overview');
        }

        if (window.Turbo && typeof window.Turbo.visit === 'function') {
            window.Turbo.visit(url.toString());
            return;
        }

        window.location.assign(url.toString());
    }

    fitMap(mapOrContext, expanded) {
        const map = mapOrContext?.map ?? mapOrContext;
        if (!map) {
            return;
        }

        const context = mapOrContext?.map
            ? mapOrContext
            : expanded
              ? this.expandMap
              : this.compactMap;
        const bounds = this.focusBounds(context, expanded);
        if (bounds?.isValid?.()) {
            map.fitBounds(bounds, {
                padding: [24, 24],
                maxZoom: 14,
                animate: false,
            });
            return;
        }

        const hospital = this.hospitalLatLng();
        if (hospital) {
            map.setView([hospital.lat, hospital.lng], 12);
            return;
        }

        map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
    }

    focusBounds(context, expanded) {
        const corners = [];

        this.pushBounds(corners, this.choroplethFocusBounds(context?.geoLayer));
        if (this.isHospitalAnalysis()) {
            this.pushBounds(corners, layerBounds(context?.ringLayer));
            this.pushHospitalPoint(corners, expanded);
        } else if (this.selectedDispatchAreaId() === null) {
            this.pushBounds(corners, layerBounds(context?.pinLayer));
        }

        if (corners.length > 0) {
            return L.latLngBounds(corners);
        }

        this.pushHospitalPoint(corners, expanded);

        return corners.length > 0 ? L.latLngBounds(corners) : null;
    }

    hospitalLatLng() {
        const hospital = this.payloadValue?.hospital;
        const lat = Number(hospital?.lat);
        const lng = Number(hospital?.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return null;
        }

        return { lat, lng };
    }

    choroplethFocusBounds(geoLayer) {
        if (!geoLayer) {
            return null;
        }

        const values = this.choroplethValues();
        const selectedKey = this.selectedGeoKey(values);
        const corners = [];

        geoLayer.eachLayer((layer) => {
            const key = layer.feature?.properties?.key ?? '';
            const entry = values.get(key);
            const featureBounds = layer.getBounds?.();
            if (!featureBounds?.isValid?.()) {
                return;
            }

            const selected = selectedKey !== null && key === selectedKey;
            const isAssigningOrigin = Boolean(entry) && Number(entry.caseCount) > 0;
            if (!selected && !isAssigningOrigin) {
                return;
            }

            corners.push(featureBounds.getSouthWest(), featureBounds.getNorthEast());
        });

        return corners.length > 0 ? L.latLngBounds(corners) : null;
    }

    isHospitalAnalysis() {
        return this.payloadValue?.analysisLevel === 'hospital' || this.hospitalLatLng() !== null;
    }

    selectedGeoKey(values) {
        const selectedId = this.selectedDispatchAreaId();
        if (selectedId === null) {
            return null;
        }

        for (const [key, entry] of values) {
            if (Number(entry.dispatchAreaId) === selectedId) {
                return key;
            }
        }

        const features = this.payloadValue?.mapFeatures ?? [];
        for (const feature of features) {
            if (
                Number(feature.dispatchAreaId) === selectedId &&
                typeof feature.geoKey === 'string'
            ) {
                return feature.geoKey;
            }
        }

        return null;
    }

    pushBounds(corners, bounds) {
        if (!bounds?.isValid?.()) {
            return;
        }

        corners.push(bounds.getSouthWest(), bounds.getNorthEast());
    }

    pushHospitalPoint(corners, expanded) {
        const hospital = this.payloadValue?.hospital;
        const lat = Number(hospital?.lat);
        const lng = Number(hospital?.lng);
        if (
            !Number.isFinite(lat) ||
            !Number.isFinite(lng) ||
            !this.layerEnabled('hospitalPin', expanded)
        ) {
            return;
        }

        corners.push(L.latLng(lat, lng));
    }

    refreshChoroplethStyles(context) {
        if (!context?.geoLayer) {
            return;
        }

        const values = this.choroplethValues();
        context.geoLayer.eachLayer((layer) => {
            const feature = layer.feature;
            if (!feature) {
                return;
            }
            layer.setStyle(
                choroplethStyleForFeature(
                    feature,
                    values,
                    this.selectedDispatchAreaId(),
                    this.selectedOriginDispatchAreaId(),
                ),
            );
            layer.unbindTooltip();
            layer.bindTooltip(
                choroplethTooltip(feature, values, this.casesLabelValue, this.shareLabelValue),
                { sticky: true },
            );
        });
        context.labelLayer = syncChoroplethLabels(
            context.map,
            context.geoLayer,
            values,
            context.labelLayer,
            { expanded: context === this.expandMap },
        );
    }

    choroplethValues() {
        return choroplethValueByKey(this.payloadValue?.mapFeatures ?? [], this.mapMode);
    }

    isochroneBands() {
        return this.payloadValue?.isochrone?.bands ?? this.payloadValue?.bands ?? [];
    }

    needsChoropleth(expanded) {
        return this.layerEnabled('originChoropleth', expanded) && Boolean(this.geoUrlValue);
    }

    layerEnabled(layer, expanded) {
        return this.enabledLayers(expanded).has(layer);
    }

    enabledLayers(expanded) {
        if (expanded && this.expandedLayerOverrides instanceof Set) {
            return new Set(this.expandedLayerOverrides);
        }

        const payload = this.payloadValue ?? {};
        const listed = expanded
            ? (payload.expandedLayers ?? payload.layers ?? [])
            : (payload.compactLayers ?? payload.layers ?? this.defaultCompactLayers());

        if (Array.isArray(listed) && listed.length > 0) {
            return new Set(listed);
        }

        return new Set(this.defaultCompactLayers());
    }

    defaultCompactLayers() {
        const payload = this.payloadValue ?? {};
        if (Array.isArray(payload.mapFeatures) && payload.mapFeatures.length > 0) {
            return ['originChoropleth'];
        }
        if (payload.hospital || this.isochroneBands().length > 0) {
            return ['isochroneBands', 'hospitalPin'];
        }

        return [];
    }

    async loadBoundaries() {
        return loadGeoJson(this.geoUrlValue);
    }

    syncModeButtons() {
        this.toggleActive(
            this.hasModeAbsoluteTarget ? this.modeAbsoluteTarget : null,
            this.mapMode === 'absolute',
        );
        this.toggleActive(
            this.hasModeRelativeTarget ? this.modeRelativeTarget : null,
            this.mapMode === 'relative',
        );
        this.toggleActive(
            this.hasExpandModeAbsoluteTarget ? this.expandModeAbsoluteTarget : null,
            this.mapMode === 'absolute',
        );
        this.toggleActive(
            this.hasExpandModeRelativeTarget ? this.expandModeRelativeTarget : null,
            this.mapMode === 'relative',
        );
        this.syncModeLegends();
    }

    syncModeLegends() {
        this.absoluteLegendTargets.forEach((legend) => {
            legend.classList.toggle('d-none', this.mapMode !== 'absolute');
        });
        this.relativeLegendTargets.forEach((legend) => {
            legend.classList.toggle('d-none', this.mapMode !== 'relative');
        });
    }

    toggleActive(button, active) {
        if (!button) {
            return;
        }
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    syncLayerToggles() {
        if (!this.hasLayerToggleTarget) {
            return;
        }

        const enabled = this.enabledLayers(true);
        this.layerToggleTargets.forEach((input) => {
            input.checked = enabled.has(input.dataset.layer);
        });
    }

    isExpanded() {
        return (
            this.hasExpandOverlayTarget && this.expandOverlayTarget.classList.contains('is-open')
        );
    }

    closeExpand({ skipRender = false } = {}) {
        if (this.hasExpandOverlayTarget) {
            this.expandOverlayTarget.classList.remove('is-open');
            this.expandOverlayTarget.setAttribute('aria-hidden', 'true');
        }
        document.body.classList.remove('geo-map-expand-open');
        this.destroyExpand();
        this.expandedLayerOverrides = null;
        if (!skipRender && this.compactMap) {
            ensureContainerSize(this.mapContainerTarget, this.frameSelectorValue);
            invalidateMapSize(this.compactMap.map);
        }
    }

    destroyCompact() {
        destroyLeafletMap(this.compactMap?.map);
        this.compactMap = null;
    }

    destroyExpand() {
        destroyLeafletMap(this.expandMap?.map);
        this.expandMap = null;
    }

    showMapError(container, error) {
        if (!container) {
            return;
        }

        container.innerHTML = `<div class="alert alert-warning mb-0" role="alert">Map could not be loaded: ${escapeHtml(error.message)}</div>`;
    }
}
