let cachedDrawerHtml = null;
let cachedDrawerUrl = null;

export function getCachedDrawerHtml(url) {
    if (cachedDrawerUrl === url && null !== cachedDrawerHtml) {
        return cachedDrawerHtml;
    }

    return null;
}

export function setCachedDrawerHtml(url, html) {
    cachedDrawerUrl = url;
    cachedDrawerHtml = html;
}

export function parseDrawerHtml(html) {
    const template = document.createElement('template');
    template.innerHTML = html.trim();
    const content = template.content.firstElementChild;

    if (!(content instanceof HTMLElement)) {
        return null;
    }

    return content;
}

export function updateIndicatorBadge(content, badge = null) {
    const badgeElement =
        badge ?? document.querySelector('[data-testid="stats-data-quality-indicator-badge"]');
    if (!(badgeElement instanceof HTMLElement)) {
        return;
    }

    const badgeClass = content.getAttribute('data-quality-indicator-badge-class');
    const badgeLabel = content.getAttribute('data-quality-indicator-badge-label');

    if (!badgeClass || !badgeLabel) {
        return;
    }

    badgeElement.textContent = badgeLabel;
    badgeElement.className = `badge ${badgeClass}`;
    badgeElement.classList.remove('d-none');
}

export async function fetchDrawerContent(url) {
    const cachedHtml = getCachedDrawerHtml(url);
    if (null !== cachedHtml) {
        return parseDrawerHtml(cachedHtml);
    }

    const response = await fetch(url, {
        headers: { Accept: 'text/html' },
    });

    if (!response.ok) {
        return null;
    }

    const html = await response.text();
    setCachedDrawerHtml(url, html);

    return parseDrawerHtml(html);
}

export function scheduleIdle(callback) {
    if ('requestIdleCallback' in window) {
        requestIdleCallback(() => callback(), { timeout: 2000 });
    } else {
        setTimeout(callback, 100);
    }
}
