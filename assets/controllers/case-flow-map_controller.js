import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import {
    createLeafletMap,
    destroyLeafletMap,
    ensureContainerSize,
    invalidateMapSize,
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

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
        geoUrl: String,
    };

    static targets = ['mapContainer', 'modeAbsolute', 'modeRelative'];

    connect() {
        this.mapMode = 'relative';
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.boundInvalidateSize = () => invalidateMapSize(this.map);
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
        this.modeAbsoluteTarget?.classList.toggle('active', this.mapMode === 'absolute');
        this.modeRelativeTarget?.classList.toggle('active', this.mapMode === 'relative');
    }

    async renderMap(generation) {
        if (!this.hasMapContainerTarget) {
            return;
        }

        try {
            const geojson = await loadGeoJson(this.geoUrlValue);
            if (generation !== this._renderGeneration) {
                return;
            }

            this.destroyMap();
            prepareMapContainer(this.mapContainerTarget);
            ensureContainerSize(this.mapContainerTarget, '.case-flow-map-square');

            this.map = createLeafletMap(this.mapContainerTarget, { scrollWheelZoom: true });
            const values = this.valueByKey();
            this.geoLayer = L.geoJSON(geojson, {
                style: (feature) => choroplethStyleForFeature(feature, values),
                onEachFeature: (feature, layer) => {
                    layer.bindTooltip(choroplethTooltip(feature, values, 'Cases', 'Share'), {
                        sticky: true,
                    });
                },
            }).addTo(this.map);
            this.labelLayer = syncChoroplethLabels(this.map, this.geoLayer, values, null);

            const bounds = this.geoLayer.getBounds();
            if (bounds.isValid()) {
                this.map.fitBounds(bounds, { padding: [16, 16] });
            } else {
                this.map.setView([50.55, 9.0], 8);
            }

            this.syncModeButtons();
            scheduleInvalidateSize(this.map);
        } catch (error) {
            this.mapContainerTarget.innerHTML = `<div class="alert alert-warning mb-0" role="alert">Map could not be loaded: ${error.message}</div>`;
        }
    }

    valueByKey() {
        return choroplethValueByKey(this.payloadValue?.mapFeatures ?? [], this.mapMode);
    }

    updateOverlayStyles() {
        if (!this.geoLayer) {
            return;
        }

        const values = this.valueByKey();
        this.geoLayer.eachLayer((layer) => {
            const feature = layer.feature;
            if (!feature) {
                return;
            }
            layer.setStyle(choroplethStyleForFeature(feature, values));
            layer.unbindTooltip();
            layer.bindTooltip(choroplethTooltip(feature, values, 'Cases', 'Share'), {
                sticky: true,
            });
        });
        this.labelLayer = syncChoroplethLabels(this.map, this.geoLayer, values, this.labelLayer);
    }

    destroyMap() {
        destroyLeafletMap(this.map);
        this.map = null;
        this.geoLayer = null;
        this.labelLayer = null;
    }
}
