import { Controller } from '@hotwired/stimulus';
import ApexCharts from 'apexcharts';

export default class extends Controller {
    static values = {
        rows: Array,
        dimensionsLabels: Object,
        measuresLabels: Object,
    };

    static targets = ['chart'];

    connect() {
        this.chart = null;

        // Controls aus dem Pivot-Controller "anzapfen"
        this.rowSelect = document.querySelector('[data-hospital-pivot-target="rowDimension"]');
        this.colSelect = document.querySelector('[data-hospital-pivot-target="columnDimension"]');
        this.measureSelect = document.querySelector('[data-hospital-pivot-target="measure"]');
        this.modeSelect = document.querySelector('[data-hospital-pivot-target="viewMode"]');

        this.handleChange = this.render.bind(this);

        [this.rowSelect, this.colSelect, this.measureSelect, this.modeSelect]
            .filter(Boolean)
            .forEach(el => el.addEventListener('change', this.handleChange));

        this.render();
    }

    disconnect() {
        [this.rowSelect, this.colSelect, this.measureSelect, this.modeSelect]
            .filter(Boolean)
            .forEach(el => el.removeEventListener('change', this.handleChange));

        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }

    getMode() {
        return this.modeSelect ? this.modeSelect.value : 'all';
    }
    getRowsForMode(mode) {
        if (mode === 'participants') {
            return this.rowsValue.filter(r => !!r.isParticipating);
        }

        return this.rowsValue;
    }

    buildSeries(rowDim, colDim, measure, mode) {
        let rows;
        if (mode === 'participants') {
            rows = this.rowsValue.filter(r => !!r.isParticipating);
        } else {
            rows = this.rowsValue;
        }

        const categoriesSet = new Set();

        rows.forEach(r => {
            const key = r[rowDim] ?? '(none)';
            categoriesSet.add(key);
        });

        const categories = Array.from(categoriesSet);

        const agg = {}; // seriesKey -> rowKey -> {sum, count}
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

        if (mode === 'comparison') {
            const allRows = this.rowsValue;
            const participantsRows = this.rowsValue.filter(r => !!r.isParticipating);

            const allCategoriesSet = new Set();
            [...allRows, ...participantsRows].forEach(r => {
                const key = r[rowDim] ?? '(none)';
                allCategoriesSet.add(key);
                });

            const categories = Array.from(allCategoriesSet);

            const aggregate = (rowsForAgg) => {
                const agg = {}; // rowKey -> {sum, count}

                    rowsForAgg.forEach(r => {
                        const rowKey = r[rowDim] ?? '(none)';
                        if (!agg[rowKey]) {
                            agg[rowKey] = { sum: 0, count: 0 };
                        }

                        const val = getMeasureValue(r);

                        if (measure === 'hospitalCount') {
                            agg[rowKey].sum += 1;
                            agg[rowKey].count += 1;
                        } else if (measure === 'avgBeds') {
                            if (val !== null && val !== undefined) {
                                agg[rowKey].sum += val;
                                agg[rowKey].count += 1;
                            }
                        } else if (measure === 'allocationCount') {
                            agg[rowKey].sum += val;
                        }
                    });

                    return agg;
                };

            const aggAll = aggregate(allRows);
            const aggParticipants = aggregate(participantsRows);

            const toData = (aggMap) => categories.map(cat => {
                const cell = aggMap[cat];
                if (!cell) {
                    return 0;
                }

                if (measure === 'avgBeds') {
                    return cell.count > 0 ? cell.sum / cell.count : 0;
                }

                return cell.sum;
            });

            const series = [
                { name: 'All hospitals', data: toData(aggAll) },
                { name: 'Participating hospitals', data: toData(aggParticipants) },
                ];

            return { categories, series };
        }

        rows.forEach(r => {
            const rowKey = r[rowDim] ?? '(none)';
            const seriesKey = colDim ? (r[colDim] ?? '(none)') : 'All';

            if (!agg[seriesKey]) {
                agg[seriesKey] = {};
            }
            if (!agg[seriesKey][rowKey]) {
                agg[seriesKey][rowKey] = { sum: 0, count: 0 };
            }

            const val = getMeasureValue(r);

            if (measure === 'hospitalCount') {
                agg[seriesKey][rowKey].sum += 1;
                agg[seriesKey][rowKey].count += 1;
            } else if (measure === 'avgBeds') {
                if (val !== null && val !== undefined) {
                    agg[seriesKey][rowKey].sum += val;
                    agg[seriesKey][rowKey].count += 1;
                }
            } else if (measure === 'allocationCount') {
                agg[seriesKey][rowKey].sum += val;
            }
        });

        const series = Object.keys(agg).map(seriesKey => {
            const points = categories.map(cat => {
                const cell = agg[seriesKey][cat];
                if (!cell) {
                    return 0;
                }

                if (measure === 'avgBeds') {
                    return cell.count > 0 ? cell.sum / cell.count : 0;
                }

                return cell.sum;
            });

            return {
                name: seriesKey,
                data: points,
            };
        });

        return { categories, series };
    }

    render() {
        if (!ApexCharts) {
            console.warn('[hospital-pivot-chart] ApexCharts import is not available');
            return;
        }

        const mode = this.getMode();

        const rowDim = this.rowSelect?.value || 'tier';
        const colDim = this.colSelect?.value || '';
        const measure = this.measureSelect?.value || 'hospitalCount';

        const { categories, series } = this.buildSeries(rowDim, colDim || null, measure, mode);

        let maxVal = 0;
        series.forEach(s => {
            (s.data || []).forEach(v => {
                if (v > maxVal) {
                    maxVal = v;
                }
            });
        });

        const dimLabels = this.dimensionsLabelsValue || {};
        const measureLabels = this.measuresLabelsValue || {};

        const rowDimLabel = dimLabels[rowDim] || rowDim;
        const colDimLabel = colDim ? (dimLabels[colDim] || colDim) : null;
        const measureLabel = measureLabels[measure] || measure;

        const isStacked = mode !== 'comparison' && !!colDim;

        const options = {
            chart: {
                type: 'bar',
                stacked: isStacked,
                height: 320,
            },
            series: series,
            xaxis: {
                categories: categories,
                title: {
                    text: rowDimLabel,
                },
            },
            yaxis: {
                title: {
                    text: measureLabel,
                },
                decimalsInFloat: 0,
                min: 0,
                max: maxVal === 0 ? undefined : Math.ceil(maxVal / 50) * 50,
                tickAmount: maxVal === 0 ? 1 : 5,
            },
            legend: {
                position: 'top',
            },
            dataLabels: {
                enabled: false,
            },
            tooltip: {
                y: {
                    formatter: (val) => {
                        if (measure === 'avgBeds') {
                            return `${val.toFixed(1)} beds`;
                        }
                        if (measure === 'hospitalCount') {
                            return `${val} hospitals`;
                        }
                        if (measure === 'allocationCount') {
                            return `${val} allocations`;
                        }
                        return val;
                    },
                },
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                },
            },
        };

        if (this.chart) {
            this.chart.updateOptions(options, true, true);
        } else {
            this.chart = new ApexCharts(this.chartTarget, options);
            this.chart.render();
        }
    }
}
