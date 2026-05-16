let monitoringInitialized = false;

export function initMonitoring() {
    if (monitoringInitialized) {
        return;
    }

    monitoringInitialized = true;

    // Provider integration (e.g. Sentry) belongs here and loads only after consent.
    window.dispatchEvent(new CustomEvent('monitoring:enabled'));
}

void initMonitoring();
