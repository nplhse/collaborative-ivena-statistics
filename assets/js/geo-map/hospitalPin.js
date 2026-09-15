import L from 'leaflet';

export function hospitalPinIcon({ color = '#343a40', className = 'geo-map-pin', size = 24 } = {}) {
    const anchor = Math.round(size / 2);

    return L.divIcon({
        className,
        html: `<svg viewBox="0 0 24 24" width="${size}" height="${size}" aria-hidden="true"><path fill="${color}" stroke="#ffffff" stroke-width="1.5" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/><circle fill="#ffffff" cx="12" cy="9" r="2.5"/></svg>`,
        iconSize: [size, size],
        iconAnchor: [anchor, size],
        tooltipAnchor: [0, -18],
    });
}

export function outflowPinColor() {
    return '#e8590c';
}

export function destinationPinColor(tierCode) {
    if (tierCode === 3) {
        return '#1864ab';
    }
    if (tierCode === 2) {
        return '#339af0';
    }
    if (tierCode === 1) {
        return '#74c0fc';
    }

    return '#868e96';
}

export function destinationPinSize(caseCount, maxCount) {
    const ratio = maxCount > 0 ? caseCount / maxCount : 0;
    return Math.round(18 + ratio * 12);
}
