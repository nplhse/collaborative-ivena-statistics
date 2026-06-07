/**
 * ApexCharts heatmaps render the first series at the bottom of the y-axis.
 * Reverse bucket order so chronological buckets read top-to-bottom (morning → night).
 */
export function buildHeatmapSeries(columnLabels, rowLabels, matrix) {
    const columnCount = columnLabels.length;

    return [...columnLabels].reverse().map((_colLabel, visualIndex) => {
        const colIndex = columnCount - 1 - visualIndex;
        const colLabel = columnLabels[colIndex];

        return {
            name: colLabel,
            data: rowLabels.map((rowLabel, rowIndex) => ({
                x: rowLabel,
                y: matrix[rowIndex]?.[colIndex] ?? 0,
            })),
        };
    });
}

export function heatmapColumnIndexFromSeriesIndex(seriesIndex, columnCount) {
    return columnCount - 1 - seriesIndex;
}
