/**
 * Prepares the page for the browser's print dialog (#969). Works for the print button and for the
 * browser menu, so chart controllers need no print code of their own.
 */
const PREPARED_ATTRIBUTE = 'data-print-viewbox';
const FRAME_TIMEOUT_MS = 8000;
const PIXEL_SIZE = /^\d+(\.\d+)?(px)?$/;

/**
 * ApexCharts draws at the width of the screen. A viewBox lets print.css scale the finished SVG to the
 * paper without redrawing it, which would be too late inside beforeprint.
 */
export function fitChartsToPaper(root) {
    for (const svg of root.querySelectorAll('svg.apexcharts-svg')) {
        if (svg.hasAttribute('viewBox')) {
            continue;
        }

        const width = svg.getAttribute('width') ?? '';
        const height = svg.getAttribute('height') ?? '';
        if (!PIXEL_SIZE.test(width) || !PIXEL_SIZE.test(height)) {
            continue;
        }

        svg.setAttribute('viewBox', `0 0 ${parseFloat(width)} ${parseFloat(height)}`);
        svg.setAttribute(PREPARED_ATTRIBUTE, '');
    }
}

export function restoreChartsAfterPrint(root) {
    for (const svg of root.querySelectorAll(`svg[${PREPARED_ATTRIBUTE}]`)) {
        svg.removeAttribute('viewBox');
        svg.removeAttribute(PREPARED_ATTRIBUTE);
    }
}

/** The page may have been open for a while: the printout names the moment of printing. */
export function updatePrintTime(root, now, locale) {
    const text = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'long' }).format(
        now,
    );

    for (const element of root.querySelectorAll('[data-print-time]')) {
        element.textContent = text;
    }
}

/** Loads lazy frames below the fold, so the printout does not miss sections nobody scrolled to. */
export async function loadPendingFrames(root, timeoutMs = FRAME_TIMEOUT_MS) {
    const frames = [...root.querySelectorAll('turbo-frame[src][loading="lazy"]:not([complete])')];
    if (frames.length === 0) {
        return;
    }

    for (const frame of frames) {
        frame.setAttribute('loading', 'eager');
    }

    let timer;
    await Promise.race([
        Promise.allSettled(frames.map((frame) => frame.loaded)),
        new Promise((resolve) => {
            timer = setTimeout(resolve, timeoutMs);
        }),
    ]);
    clearTimeout(timer);
}

export function installPrintPreparation(target, root) {
    target.addEventListener('beforeprint', () => {
        fitChartsToPaper(root);
        updatePrintTime(root, new Date(), root.documentElement.lang || undefined);
    });
    target.addEventListener('afterprint', () => restoreChartsAfterPrint(root));
}
