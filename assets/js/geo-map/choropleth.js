import L from 'leaflet';
import { escapeHtml } from './escapeHtml.js';

/** Sequential blues (ColorBrewer) — keep in sync with the Twig legend swatches. */
export const CHOROPLETH_COLOR_STOPS = ['#deebf7', '#9ecae1', '#4292c6', '#2171b5', '#08306b'];

export function choroplethValueByKey(features, mapMode) {
    const valueByKey = new Map();
    const list = Array.isArray(features) ? features : [];
    const mode = mapMode === 'absolute' ? 'absolute' : 'relative';

    list.forEach((feature) => {
        valueByKey.set(feature.geoKey, {
            mapMode: mode,
            value: mode === 'relative' ? feature.sharePercent : feature.caseCount,
            suppressed: feature.suppressed,
            originName: feature.originName,
            caseCount: feature.caseCount,
            sharePercent: feature.sharePercent,
            dispatchAreaId: feature.dispatchAreaId,
        });
    });

    return valueByKey;
}

export function maxVisibleChoroplethValue(valueByKey) {
    const values = [...valueByKey.values()]
        .filter((entry) => !entry.suppressed && entry.value > 0)
        .map((entry) => entry.value);

    return values.length > 0 ? Math.max(...values) : 1;
}

export function choroplethScaleMax(valueByKey) {
    const mode = [...valueByKey.values()][0]?.mapMode ?? 'relative';
    if (mode === 'relative') {
        return 100;
    }

    return maxVisibleChoroplethValue(valueByKey);
}

export function choroplethStyleForFeature(
    feature,
    valueByKey,
    selectedDispatchAreaId = null,
    selectedOriginDispatchAreaId = null,
) {
    const key = feature.properties?.key ?? '';
    const entry = valueByKey.get(key);
    const scaleMax = choroplethScaleMax(valueByKey);
    const suppressed = !entry || entry.suppressed || entry.value <= 0;
    const selectedOrigin = isSelectedDispatchArea(entry, selectedOriginDispatchAreaId);
    const selectedScope = isSelectedDispatchArea(entry, selectedDispatchAreaId);
    const selected = selectedOrigin || selectedScope;
    const outline = selectedOrigin ? '#0ca678' : '#e8590c';

    if (suppressed) {
        return {
            fillColor: '#ced4da',
            weight: selected ? 3 : 1,
            opacity: 1,
            color: selected ? outline : '#868e96',
            fillOpacity: 0.28,
        };
    }

    const intensity = contrastRatio(entry.value, scaleMax);

    return {
        fillColor: colorForValue(entry.value, scaleMax),
        weight: selected ? 3 : 1.4 + intensity * 1.6,
        opacity: 1,
        color: selected ? outline : intensity > 0.55 ? '#08306b' : '#345e7d',
        fillOpacity: 0.58 + intensity * 0.32,
    };
}

export function choroplethTooltip(feature, valueByKey, casesLabel, shareLabel) {
    const key = feature.properties?.key ?? '';
    const entry = valueByKey.get(key);
    const name = feature.properties?.name ?? key;

    if (!entry) {
        return `${escapeHtml(name)}: n/a`;
    }

    if (entry.suppressed) {
        return `${escapeHtml(name)}: suppressed (n &lt; 10)`;
    }

    const count = formatCaseCount(entry.caseCount) || String(entry.caseCount);
    const share = formatSharePercent(entry.sharePercent) || `${entry.sharePercent}%`;
    const countLine = `${escapeHtml(casesLabel)}: ${count}`;
    const shareLine = `${escapeHtml(shareLabel)}: ${share}`;

    return [
        `<strong>${escapeHtml(name)}</strong>`,
        entry.mapMode === 'absolute' ? `<strong>${countLine}</strong>` : countLine,
        entry.mapMode === 'relative' ? `<strong>${shareLine}</strong>` : shareLine,
    ].join('<br/>');
}

export function formatSharePercent(sharePercent) {
    const percent = Number(sharePercent);
    if (!Number.isFinite(percent) || percent <= 0) {
        return '';
    }

    const rounded = Math.round(percent * 10) / 10;
    if (rounded <= 0) {
        return '';
    }

    return Number.isInteger(rounded) ? `${rounded.toFixed(0)}%` : `${rounded.toFixed(1)}%`;
}

export function formatCaseCount(caseCount) {
    const count = Number(caseCount);
    if (!Number.isFinite(count) || count <= 0) {
        return '';
    }

    const locale = document.documentElement?.lang || 'de';

    return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(count);
}

export function choroplethLabelText(entry) {
    if (!entry || entry.suppressed) {
        return '';
    }

    if (entry.mapMode === 'absolute') {
        return formatCaseCount(entry.caseCount);
    }

    return formatSharePercent(entry.sharePercent);
}

export function syncChoroplethLabels(
    map,
    geoLayer,
    valueByKey,
    existingGroup,
    { expanded = false } = {},
) {
    if (existingGroup) {
        existingGroup.remove();
    }

    if (!map || !geoLayer) {
        return null;
    }

    const group = L.layerGroup();
    const scaleMax = choroplethScaleMax(valueByKey);

    geoLayer.eachLayer((layer) => {
        const feature = layer.feature;
        if (!feature || typeof layer.getBounds !== 'function') {
            return;
        }

        const bounds = layer.getBounds();
        if (!bounds.isValid()) {
            return;
        }

        const key = feature.properties?.key ?? '';
        const entry = valueByKey.get(key);
        const text = choroplethLabelText(entry);
        if (!text) {
            return;
        }

        const intensity = contrastRatio(entry.value, scaleMax);
        const onDark = intensity > 0.48;
        const classNames = [
            'geo-map-share-label',
            onDark ? 'geo-map-share-label--on-dark' : '',
            expanded ? 'geo-map-share-label--expanded' : '',
        ]
            .filter(Boolean)
            .join(' ');

        const marker = L.marker(bounds.getCenter(), {
            icon: L.divIcon({
                className: classNames,
                html: `<span>${escapeHtml(text)}</span>`,
                iconSize: [0, 0],
                iconAnchor: [0, 0],
            }),
            interactive: false,
            keyboard: false,
            zIndexOffset: 400,
        });
        group.addLayer(marker);
    });

    group.addTo(map);

    return group;
}

function isSelectedDispatchArea(entry, selectedDispatchAreaId) {
    if (selectedDispatchAreaId == null || !entry) {
        return false;
    }

    return Number(entry.dispatchAreaId) === Number(selectedDispatchAreaId);
}

function contrastRatio(value, maxValue) {
    const linear = maxValue > 0 ? value / maxValue : 0;

    return Math.sqrt(Math.min(1, Math.max(0, linear)));
}

function colorForValue(value, maxValue) {
    const t = contrastRatio(value, maxValue);
    const last = CHOROPLETH_COLOR_STOPS.length - 1;
    const scaled = t * last;
    const index = Math.min(last - 1, Math.floor(scaled));
    const local = scaled - index;
    const start = hexToRgb(CHOROPLETH_COLOR_STOPS[index]);
    const end = hexToRgb(CHOROPLETH_COLOR_STOPS[index + 1]);

    return rgbToHex(
        start[0] + (end[0] - start[0]) * local,
        start[1] + (end[1] - start[1]) * local,
        start[2] + (end[2] - start[2]) * local,
    );
}

function hexToRgb(hex) {
    const n = Number.parseInt(hex.slice(1), 16);

    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

function rgbToHex(r, g, b) {
    return `#${[r, g, b]
        .map((channel) => Math.round(channel).toString(16).padStart(2, '0'))
        .join('')}`;
}
