import { Controller } from '@hotwired/stimulus'
import ApexCharts from 'apexcharts'

export default class extends Controller {
    static targets = ['chart']
    static values  = { labels: Array, series: Array }

    connect() {
        console.log('[hourly-chart] connect', {
            labels: this.labelsValue?.length ?? 0,
            series: this.seriesValue?.length ?? 0,
            hasChartTarget: this.hasChartTarget
        })

        if (!this.hasChartTarget) return
        if (!Array.isArray(this.seriesValue) || this.seriesValue.length === 0) return

        if (!this.chartTarget.style.height) this.chartTarget.style.height = '280px'

        const options = {
            chart: { type: 'bar', height: this.chartTarget.clientHeight || 280, toolbar: { show: false } },
            series: this.seriesValue,               // [{ name:'Total', data:[…24…] }, …]
            xaxis:  { categories: this.labelsValue, labels: { rotate: -45 } },
            plotOptions: { bar: { columnWidth: '60%', borderRadius: 4 } },
            dataLabels: { enabled: false },
            grid: { strokeDashArray: 4 },
            legend: { show: true }
        }

        this.chart = new ApexCharts(this.chartTarget, options)
        this.chart.render().catch(err => console.error('[hourly-chart] render error', err))

        this._ro = new ResizeObserver(() => this.chart?.resize())
        this._ro.observe(this.element)
    }

    disconnect() {
        this.chart?.destroy()
        this._ro?.disconnect()
    }
}
