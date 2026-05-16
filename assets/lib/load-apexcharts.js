let apexChartsPromise = null;

export function loadApexCharts() {
    if (!apexChartsPromise) {
        apexChartsPromise = import('apexcharts').then((m) => m.default);
    }
    return apexChartsPromise;
}
