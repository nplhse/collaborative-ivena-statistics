import { Controller } from '@hotwired/stimulus';
import { loadApexCharts } from '../lib/load-apexcharts.js';

const POPULATION_BOX_PLOT_COLOR = '#868e96';
const PARTICIPANTS_BOX_PLOT_COLOR = '#339af0';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        payload: Object,
    };

    static targets = [
        'bedsCareLevelPopulationBoxPlot',
        'bedsCareLevelParticipantsBoxPlot',
        'bedsLocationPopulationBoxPlot',
        'bedsLocationParticipantsBoxPlot',
        'allocationByTierChart',
        'allocationBySizeChart',
        'allocationByLocationChart',
    ];

    connect() {
        this.instances = [];
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        void this.renderAll(this._renderGeneration);
    }

    disconnect() {
        this._renderGeneration = (this._renderGeneration ?? 0) + 1;
        this.instances.forEach((chart) => chart.destroy());
        this.instances = [];
    }

    async renderAll(generation) {
        const ApexCharts = await loadApexCharts();
        if (generation !== this._renderGeneration) {
            return;
        }

        const payload = this.payloadValue ?? {};

        await this.renderSplitBoxPlots(
            ApexCharts,
            payload.bedsBoxPlotByCareLevel,
            this.hasBedsCareLevelPopulationBoxPlotTarget
                ? this.bedsCareLevelPopulationBoxPlotTarget
                : null,
            this.hasBedsCareLevelParticipantsBoxPlotTarget
                ? this.bedsCareLevelParticipantsBoxPlotTarget
                : null,
            generation,
        );

        await this.renderSplitBoxPlots(
            ApexCharts,
            payload.bedsBoxPlotByLocation,
            this.hasBedsLocationPopulationBoxPlotTarget
                ? this.bedsLocationPopulationBoxPlotTarget
                : null,
            this.hasBedsLocationParticipantsBoxPlotTarget
                ? this.bedsLocationParticipantsBoxPlotTarget
                : null,
            generation,
        );

        this.renderCategoryBarChart(
            ApexCharts,
            payload.allocationByTier,
            this.hasAllocationByTierChartTarget ? this.allocationByTierChartTarget : null,
            '#1864ab',
            generation,
        );
        this.renderCategoryBarChart(
            ApexCharts,
            payload.allocationBySize,
            this.hasAllocationBySizeChartTarget ? this.allocationBySizeChartTarget : null,
            '#495057',
            generation,
        );
        this.renderCategoryBarChart(
            ApexCharts,
            payload.allocationByLocation,
            this.hasAllocationByLocationChartTarget ? this.allocationByLocationChartTarget : null,
            '#339af0',
            generation,
        );
    }

    async renderSplitBoxPlots(
        ApexCharts,
        data,
        populationElement,
        participantsElement,
        generation,
    ) {
        if (generation !== this._renderGeneration || !data) {
            return;
        }

        if (populationElement && data.population?.series?.length) {
            await this.renderSingleSeriesBoxPlot(
                ApexCharts,
                populationElement,
                data.population,
                POPULATION_BOX_PLOT_COLOR,
                generation,
            );
        }

        if (participantsElement && data.participants?.series?.length) {
            await this.renderSingleSeriesBoxPlot(
                ApexCharts,
                participantsElement,
                data.participants,
                PARTICIPANTS_BOX_PLOT_COLOR,
                generation,
            );
        }
    }

    async renderSingleSeriesBoxPlot(ApexCharts, element, data, color, generation) {
        if (generation !== this._renderGeneration || !element) {
            return;
        }

        const chart = new ApexCharts(element, {
            chart: {
                type: 'boxPlot',
                height: 260,
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: data.series ?? [],
            colors: [color],
            plotOptions: {
                boxPlot: {
                    colors: {
                        upper: color,
                        lower: color,
                    },
                },
            },
            xaxis: {
                type: 'category',
                labels: {
                    trim: false,
                },
            },
            yaxis: {
                labels: {
                    formatter: (value) => Math.round(value),
                },
            },
            legend: { show: false },
        });

        await chart.render();
        this.instances.push(chart);
    }

    renderCategoryBarChart(ApexCharts, data, element, color, generation) {
        if (generation !== this._renderGeneration || !element || !data?.categories?.length) {
            return;
        }

        const chart = new ApexCharts(element, {
            chart: {
                type: 'bar',
                height: 220,
                toolbar: { show: false },
                fontFamily: 'inherit',
            },
            series: data.series ?? [],
            colors: [color],
            xaxis: {
                categories: data.categories ?? [],
                labels: { trim: false },
            },
            plotOptions: {
                bar: { horizontal: false, columnWidth: '55%' },
            },
            legend: { show: false },
            dataLabels: { enabled: false },
            yaxis: {
                labels: {
                    formatter: (value) => Math.round(value),
                },
            },
        });

        chart.render();
        this.instances.push(chart);
    }
}
