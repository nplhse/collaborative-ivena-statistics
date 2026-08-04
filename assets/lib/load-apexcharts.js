let apexChartsPromise = null;

export function loadApexCharts() {
    if (!apexChartsPromise) {
        apexChartsPromise = import('apexcharts').then((m) => m.default);
    }
    return apexChartsPromise;
}

/**
 * CSP style nonce from EasyAdmin's <meta name="csp-nonce"> when present.
 * Without a meta tag (normal app pages), ApexCharts relies on style-src 'unsafe-inline'.
 */
export function getCspNonce() {
    return document.querySelector('meta[name="csp-nonce"]')?.content || undefined;
}

/**
 * Merge chart.nonce into ApexCharts options when a CSP nonce is available.
 *
 * @param {Record<string, unknown>} options
 * @returns {Record<string, unknown>}
 */
export function applyChartNonce(options) {
    const nonce = getCspNonce();
    if (!nonce) {
        return options;
    }

    return {
        ...options,
        chart: {
            ...(options.chart ?? {}),
            nonce,
        },
    };
}
