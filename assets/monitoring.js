let monitoringInitialized = false;

export function initMonitoring() {
    if (monitoringInitialized) {
        return;
    }

    monitoringInitialized = true;

    // The concrete monitoring provider integration (e.g. Sentry init)
    // is intentionally isolated here and only loaded after consent.
    window.dispatchEvent(new CustomEvent('monitoring:enabled'));
}

void initMonitoring();
