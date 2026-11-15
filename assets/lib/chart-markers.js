/**
 * Marker size of line and area charts (#1284).
 *
 * Line and area charts draw no markers, only the line. With a single point there is no line to
 * draw, so ApexCharts shows almost nothing. Every line and area chart takes its markers from
 * here: a visible marker when the chart has exactly one point, otherwise the given options.
 */
export const SINGLE_POINT_MARKER_SIZE = 4;

/**
 * @param {number} pointCount points per series (categories or x values)
 * @param {Record<string, unknown>} [markers] the chart's own marker options; `size` defaults to 0
 * @returns {Record<string, unknown>}
 */
export function lineMarkers(pointCount, markers = {}) {
    return {
        ...markers,
        size: pointCount === 1 ? SINGLE_POINT_MARKER_SIZE : (markers.size ?? 0),
    };
}

/**
 * Number of points of the longest series, for charts without a shared category list.
 *
 * @param {{ data?: unknown[] }[]} series
 * @returns {number}
 */
export function seriesPointCount(series) {
    return (series ?? []).reduce(
        (max, entry) => Math.max(max, Array.isArray(entry?.data) ? entry.data.length : 0),
        0,
    );
}
