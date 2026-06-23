/**
 * @param {string} dataUri
 * @param {string} filename
 */
export function downloadDataUri(dataUri, filename) {
    const link = document.createElement('a');
    link.download = filename;
    link.href = dataUri;
    link.click();
}

/**
 * @param {string} title
 * @param {string} extension
 */
export function buildExportFilename(title, extension) {
    const slug = String(title ?? '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    const base = slug || 'analysis-export';
    const date = new Date().toISOString().slice(0, 10);

    return `${base}-${date}.${extension}`;
}
