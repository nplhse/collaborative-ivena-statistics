import difference from '@turf/difference';
import { escapeHtml } from './escapeHtml.js';

const HEAT_GREEN = [47, 158, 68];
const HEAT_YELLOW = [250, 176, 5];
const HEAT_RED = [224, 49, 49];
export const ISOCHRONE_FILL_OPACITY = 0.25;
export const ISOCHRONE_STROKE_OPACITY = 0.9;
export const ISOCHRONE_STROKE_WEIGHT = 2;

export function buildIsochroneRings(bands) {
    const sorted = Array.isArray(bands) ? [...bands] : [];
    sorted.sort((left, right) => left.minutes - right.minutes);

    const rings = [];
    let previousGeometry = null;

    for (const band of sorted) {
        if (!band?.geometry) {
            continue;
        }

        const outer = asFeature(band.geometry, band);
        let ringFeature = outer;

        if (previousGeometry) {
            const differenced = safeDifference(outer, asFeature(previousGeometry, {}));
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
            minutes: Number(band.minutes) || 0,
            geometry: band.geometry,
        });

        previousGeometry = band.geometry;
    }

    return rings.reverse();
}

export function isochroneStyle(ring) {
    const fillColor = heatColor(Math.min(1, Math.max(0, ring.intensity)));

    return {
        color: fillColor,
        weight: ISOCHRONE_STROKE_WEIGHT,
        opacity: ISOCHRONE_STROKE_OPACITY,
        fillColor,
        fillOpacity: ISOCHRONE_FILL_OPACITY,
        interactive: true,
    };
}

export function isochroneTooltip(ring, casesLabel, shareLabel) {
    const sharePercent = (ring.share * 100).toFixed(1);

    return [
        `<strong>${escapeHtml(ring.label)}</strong>`,
        `${escapeHtml(casesLabel)}: ${ring.count}`,
        `${escapeHtml(shareLabel)}: ${sharePercent}%`,
    ].join('<br/>');
}

export function asFeature(geometry, properties) {
    return {
        type: 'Feature',
        properties: properties ?? {},
        geometry,
    };
}

function safeDifference(outer, inner) {
    try {
        return difference(outer, inner);
    } catch {
        return null;
    }
}

function heatColor(t) {
    if (t < 0.5) {
        return mixColor(HEAT_GREEN, HEAT_YELLOW, t * 2);
    }

    return mixColor(HEAT_YELLOW, HEAT_RED, (t - 0.5) * 2);
}

function mixColor(from, to, t) {
    const red = Math.round(from[0] + (to[0] - from[0]) * t);
    const green = Math.round(from[1] + (to[1] - from[1]) * t);
    const blue = Math.round(from[2] + (to[2] - from[2]) * t);

    return `rgb(${red}, ${green}, ${blue})`;
}
