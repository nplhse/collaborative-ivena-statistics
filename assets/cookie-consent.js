const BANNER_SELECTOR = '[data-cookie-consent-banner]';
let initialized = false;

async function fetchConsentState(banner) {
    const url = banner.dataset.cookieConsentCurrentUrl;
    if (!url) {
        return null;
    }

    const response = await fetch(url, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        return null;
    }

    return response.json();
}

async function updateConsent(banner, monitoringEnabled) {
    const url = banner.dataset.cookieConsentUpdateUrl;
    const token = banner.dataset.cookieConsentToken;
    if (!url || !token) {
        return null;
    }

    const formData = new FormData();
    formData.set('_token', token);
    formData.set('monitoring', monitoringEnabled ? '1' : '0');

    const response = await fetch(url, {
        method: 'POST',
        body: formData,
        headers: {
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        return null;
    }

    return response.json();
}

function showBanner(banner) {
    banner.classList.remove('d-none');
    banner.classList.add('show');
}

function hideBanner(banner) {
    banner.classList.remove('show');
    banner.classList.add('d-none');
}

async function activateMonitoring() {
    const module = await import('./monitoring.js');
    module.initMonitoring();
}

function bindBannerActions(banner) {
    const buttons = banner.querySelectorAll('[data-cookie-consent-action]');
    buttons.forEach((button) => {
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            const action = button.dataset.cookieConsentAction;
            const monitoring = action === 'all';
            const result = await updateConsent(banner, monitoring);
            if (!result) {
                return;
            }

            hideBanner(banner);
            if (result.preferences?.monitoring) {
                await activateMonitoring();
            }
        });
    });
}

export async function initCookieConsent() {
    if (initialized) {
        return;
    }

    const banner = document.querySelector(BANNER_SELECTOR);
    if (!banner) {
        return;
    }

    initialized = true;
    bindBannerActions(banner);

    const state = await fetchConsentState(banner);
    if (!state) {
        return;
    }

    if (state.preferences?.monitoring) {
        await activateMonitoring();
    }

    if (!state.decided) {
        showBanner(banner);
    } else {
        hideBanner(banner);
    }
}
