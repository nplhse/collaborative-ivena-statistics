import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        rows: Array,
        dimensionsLabels: Object,
        measuresLabels: Object,
    };

    static targets = [
        'rowDimension',
        'columnDimension',
        'measure',
        'viewMode',
        'heatmap',
        'hideEmpty',
        'showPercentages',
        'tableHead',
        'tableBody',
        'table',
    ];

    connect() {
        this.rowDimensionTarget.addEventListener('change', () => this.render());
        this.columnDimensionTarget.addEventListener('change', () => this.render());
        this.measureTarget.addEventListener('change', () => this.render());

        if (this.hasViewModeTarget) {
            this.viewModeTarget.addEventListener('change', () => this.render());
        }
        if (this.hasHeatmapTarget) {
            this.heatmapTarget.addEventListener('change', () => this.render());
        }
        if (this.hasHideEmptyTarget) {
            this.hideEmptyTarget.addEventListener('change', () => this.render());
        }
        if (this.hasShowPercentagesTarget) {
            this.showPercentagesTarget.addEventListener('change', () => this.render());
        }

        this.render();
    }

    render() {
        const mode = this.hasViewModeTarget ? this.viewModeTarget.value : 'all';

        const rowDim = this.rowDimensionTarget.value;
        const colDim = this.columnDimensionTarget.value || null;
        const measure = this.measureTarget.value;

        const allRows = this.rowsValue;
        const participantsRows = allRows.filter(r => !!r.isParticipating);

        if (mode === 'participants') {
            const pivot = this.buildPivot(participantsRows, rowDim, colDim, measure);

            this.renderTable(pivot, rowDim, colDim, measure, { mode });
            return;
        }

        if (mode === 'comparison') {
            const pivotAll = this.buildPivot(allRows, rowDim, colDim, measure);
            const pivotParticipants = this.buildPivot(participantsRows, rowDim, colDim, measure);

            this.renderTable(pivotAll, rowDim, colDim, measure, {
                mode,
                comparisonPivot: pivotParticipants,
            });
            return;
        }

        // default: "all"
        const pivot = this.buildPivot(allRows, rowDim, colDim, measure);
        this.renderTable(pivot, rowDim, colDim, measure, { mode });
    }

    filteredRows() {
        const participantsOnly = this.hasParticipantsOnlyTarget
            ? this.participantsOnlyTarget.checked
            : false;

        if (!participantsOnly) {
            return this.rowsValue;
        }

        return this.rowsValue.filter(r => !!r.isParticipating);
    }

    buildPivot(rows, rowDim, colDim, measure) {
        const result = {
            rows: [],
            cols: [],
            values: {},
            counts: {},
        };

        const rowSet = new Set();
        const colSet = new Set();
        const agg = {}; // key -> {sum, count}

        const getMeasureValue = (row) => {
            switch (measure) {
                case 'hospitalCount':
                    return 1;
                case 'avgBeds':
                    return row.beds ?? null;
                case 'allocationCount':
                    return row.allocationCount ?? 0;
                default:
                    return 0;
            }
        };

        rows.forEach(row => {
            const rKey = row[rowDim] ?? '(none)';
            const cKey = colDim ? (row[colDim] ?? '(none)') : '_';

            rowSet.add(rKey);
            colSet.add(cKey);

            const key = `${rKey}|||${cKey}`;
            if (!agg[key]) {
                agg[key] = { sum: 0, count: 0 };
            }

            const val = getMeasureValue(row);

            if (measure === 'hospitalCount') {
                agg[key].sum += 1;
                agg[key].count += 1;
            } else if (measure === 'avgBeds') {
                if (val !== null && val !== undefined) {
                    agg[key].sum += val;
                    agg[key].count += 1;
                }
            } else if (measure === 'allocationCount') {
                agg[key].sum += val;
            }
        });

        result.rows = Array.from(rowSet);
        result.cols = colDim ? Array.from(colSet) : ['_'];

        result.rows.forEach(rKey => {
            result.values[rKey] = {};
            result.counts[rKey] = {};
            result.cols.forEach(cKey => {
                const aggKey = `${rKey}|||${cKey}`;
                const cell = agg[aggKey] || { sum: 0, count: 0 };

                let value;
                let count;

                if (measure === 'avgBeds') {
                    value = cell.count > 0 ? cell.sum / cell.count : 0;
                    count = cell.count;
                } else if (measure === 'hospitalCount') {
                    value = cell.sum;
                    count = cell.sum;
                } else { // allocationCount
                    value = cell.sum;
                    count = 0;
                }

                result.values[rKey][cKey] = value;
                result.counts[rKey][cKey] = count;
            });
        });

        return result;
    }

    renderTable(pivot, rowDim, colDim, measure, options = {}) {
        const { mode = 'all', comparisonPivot = null } = options;
        const dimLabels = this.dimensionsLabelsValue || {};
        const measureLabels = this.measuresLabelsValue || {};

        const rowDimLabel = dimLabels[rowDim] || rowDim;
        const colDimLabel = colDim ? (dimLabels[colDim] || colDim) : null;
        const measureLabel = measureLabels[measure] || measure;

        const hideEmpty = this.hasHideEmptyTarget ? this.hideEmptyTarget.checked : false;
        const showPercentages = this.hasShowPercentagesTarget ? this.showPercentagesTarget.checked : false;
        const heatmap = this.hasHeatmapTarget ? this.heatmapTarget.checked : false;

        if (this.hasTableTarget) {
            if (heatmap) {
                this.tableTarget.classList.remove('table-striped');
            } else {
                this.tableTarget.classList.add('table-striped');
            }
        }

        let rows = pivot.rows.slice();
        let cols = pivot.cols.slice();

        if (hideEmpty) {
            const rowNonZero = {};
            const colNonZero = {};

            rows.forEach(r => {
                cols.forEach(c => {
                    const v = pivot.values[r][c];
                    if (v !== 0) {
                        rowNonZero[r] = true;
                        colNonZero[c] = true;
                    }
                });
            });

            rows = rows.filter(r => rowNonZero[r]);
            cols = cols.filter(c => colNonZero[c]);
        }

        let min = Infinity;
        let max = -Infinity;

        if (heatmap) {
            rows.forEach(r => {
                cols.forEach(c => {
                    const v = pivot.values[r][c];
                    if (v < min) min = v;
                    if (v > max) max = v;
                });
            });
            if (min === Infinity) {
                min = 0;
                max = 0;
            }
        }

        const rowTotals = {};
        const colTotals = {};
        let grandTotal = 0;

        const rowBedSum = {};
        const rowBedCount = {};
        const colBedSum = {};
        const colBedCount = {};
        let grandBedSum = 0;
        let grandBedCount = 0;

        rows.forEach(r => {
            cols.forEach(c => {
                const v = pivot.values[r][c];

                if (measure === 'avgBeds') {
                    const cellCount = pivot.counts[r][c] || 0;
                    const bedSum = v * cellCount;

                    rowBedSum[r] = (rowBedSum[r] || 0) + bedSum;
                    rowBedCount[r] = (rowBedCount[r] || 0) + cellCount;

                    colBedSum[c] = (colBedSum[c] || 0) + bedSum;
                    colBedCount[c] = (colBedCount[c] || 0) + cellCount;

                    grandBedSum += bedSum;
                    grandBedCount += cellCount;
                } else {
                    rowTotals[r] = (rowTotals[r] || 0) + v;
                    colTotals[c] = (colTotals[c] || 0) + v;
                    grandTotal += v;
                }
            });
        });

        if (measure === 'avgBeds') {
            rows.forEach(r => {
                const cnt = rowBedCount[r] || 0;
                rowTotals[r] = cnt > 0 ? rowBedSum[r] / cnt : 0;
            });

            cols.forEach(c => {
                const cnt = colBedCount[c] || 0;
                colTotals[c] = cnt > 0 ? colBedSum[c] / cnt : 0;
            });

            grandTotal = grandBedCount > 0 ? grandBedSum / grandBedCount : 0;
        }

        // Header
        const head = [];
        head.push('<tr>');
        head.push('<th>' + this.escapeHtml(rowDimLabel) + '</th>');

        if (colDim) {
            cols.forEach(c => {
                head.push('<th class="text-end">' + this.escapeHtml(String(c)) + '</th>');
            });
        } else {
            head.push('<th class="text-end">' + this.escapeHtml(measureLabel) + '</th>');
        }

        head.push('<th class="text-end fw-bold">Total</th>');
        head.push('</tr>');
        this.tableHeadTarget.innerHTML = head.join('');

        // Body
        const body = [];

        rows.forEach(rKey => {
            body.push('<tr>');
            body.push('<th>' + this.escapeHtml(String(rKey)) + '</th>');

            cols.forEach(cKey => {
                const v = pivot.values[rKey][cKey];
                const vComparison = comparisonPivot
                    ? ((comparisonPivot.values[rKey] && comparisonPivot.values[rKey][cKey]) ?? 0)
                    : null;

                let style = '';
                if (heatmap && max > min) {
                    const t = (v - min) / (max - min);
                    const alpha = (0.15 + 0.5 * t).toFixed(2);
                    style = ` style="background-color: rgba(0, 123, 255, ${alpha});"`;
                }

                let tooltip;
                let display;

                if (comparisonPivot) {
                    tooltip = this.buildComparisonTooltip(
                        rowDimLabel,
                        rKey,
                        colDimLabel,
                        cKey === '_' ? null : cKey,
                        measureLabel,
                        v,
                        vComparison,
                        grandTotal,
                        showPercentages,
                        measure
                    );
                    display = this.formatComparisonValue(
                        v,
                        vComparison,
                        measure,
                        grandTotal,
                        showPercentages
                    );
                } else {
                    tooltip = this.buildCellTooltip(
                        rowDimLabel,
                        rKey,
                        colDimLabel,
                        cKey === '_' ? null : cKey,
                        measureLabel,
                        v,
                        grandTotal,
                        showPercentages,
                        measure
                    );
                    display = this.formatValue(v, measure, grandTotal, showPercentages);
                }

                body.push(
                    `<td class="text-end"${style} title="${this.escapeHtml(tooltip)}">` +
                    this.escapeHtml(display) +
                    '</td>'
                );
            });

            const rowTotal = rowTotals[rKey] ?? 0;
            body.push(
                `<td class="text-end fw-bold" title="${this.escapeHtml(measureLabel + ' total: ' + rowTotal)}">` +
                this.escapeHtml(this.formatValue(rowTotal, measure, grandTotal, showPercentages)) +
                '</td>'
            );

            body.push('</tr>');
        });

        // Footer
        const footer = [];
        footer.push('<tr>');
        footer.push('<th>Total</th>');

        cols.forEach(cKey => {
            const colTotal = colTotals[cKey] ?? 0;
            footer.push(
                `<td class="text-end fw-bold" title="${this.escapeHtml(measureLabel + ' total: ' + colTotal)}">` +
                this.escapeHtml(this.formatValue(colTotal, measure, grandTotal, showPercentages)) +
                '</td>'
            );
        });

        footer.push(
            `<td class="text-end fw-bold" title="${this.escapeHtml(measureLabel + ' total: ' + grandTotal)}">` +
            this.escapeHtml(this.formatValue(grandTotal, measure, grandTotal, showPercentages)) +
            '</td>'
        );

        footer.push('</tr>');

        this.tableBodyTarget.innerHTML = body.join('') + footer.join('');
    }

    formatValue(v, measure, grandTotal, showPercentages) {
        if (v === 0) {
            return '–';
        }

        let base;
        if (measure === 'avgBeds') {
            base = v.toFixed(1);
        } else {
            base = String(v);
        }

        if (
            showPercentages &&
            grandTotal > 0 &&
            (measure === 'hospitalCount' || measure === 'allocationCount')
        ) {
            const pct = (v / grandTotal * 100).toFixed(1);
            return `${base} (${pct}%)`;
        }

        return base;
    }

    formatComparisonValue(vAll, vParticipants, measure, grandTotal, showPercentages) {
        const allStr = this.formatValue(vAll, measure, grandTotal, showPercentages);
        const partStr = this.formatValue(vParticipants, measure, grandTotal, showPercentages);

        if (allStr === '–' && partStr === '–') {
           return '–';
        }

        return `${allStr} / ${partStr}`;
    }

    buildCellTooltip(rowDimLabel, rowValue, colDimLabel, colValue, measureLabel, v, grandTotal, showPercentages, measure) {
        const parts = [];

        parts.push(`${rowDimLabel}: ${rowValue}`);

        if (colDimLabel && colValue !== null && colValue !== undefined) {
            parts.push(`${colDimLabel}: ${colValue}`);
        }

        let valuePart = `${measureLabel}: ${v}`;
        if (
            showPercentages &&
            grandTotal > 0 &&
            (measure === 'hospitalCount' || measure === 'allocationCount')
        ) {
            const pct = (v / grandTotal * 100).toFixed(1);
            valuePart += ` (${pct}%)`;
        }
        parts.push(valuePart);

        return parts.join(' | ');
    }

    buildComparisonTooltip(
        rowDimLabel,
        rowValue,
        colDimLabel,
        colValue,
        measureLabel,
        vAll,
        vParticipants,
        grandTotal,
        showPercentages,
        measure
    ) {
        const parts = [];
        parts.push(`${rowDimLabel}: ${rowValue}`);

        if (colDimLabel && colValue !== null && colValue !== undefined) {
            parts.push(`${colDimLabel}: ${colValue}`);
        }

        const formatOne = (label, value) => {
            let txt = `${label}: ${value}`;

            if (showPercentages && grandTotal > 0 && (measure === 'hospitalCount' || measure === 'allocationCount')) {
                const pct = (value / grandTotal * 100).toFixed(1);
                txt += ` (${pct}%)`;
            }

            return txt;
        };

        parts.push(formatOne('All hospitals', vAll));
        parts.push(formatOne('Participating hospitals', vParticipants));

        return `${measureLabel} | ` + parts.join(' | ');
    }

    escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }
}
