/**
 * Whether a chart element is shown in dark mode.
 *
 * The theme attribute sits on different elements depending on the layout: app pages set
 * `data-bs-theme` on `<html>` (`@Shared/_includes/color_scheme.html.twig`), EasyAdmin sets it on
 * `<body>`. The nearest ancestor with the attribute decides, so both layouts work.
 *
 * @param {Element} element
 * @returns {boolean}
 */
export function isDarkTheme(element) {
    return element.closest('[data-bs-theme]')?.getAttribute('data-bs-theme') === 'dark';
}

/**
 * Calls `callback` when the colour scheme of a chart element changes while the page is open (#1320).
 *
 * Watches `data-bs-theme` on the whole document, so it covers both layouts (`<html>` on app pages,
 * `<body>` on EasyAdmin pages) and an attribute that is added later. The callback runs only when
 * {@link isDarkTheme} changes for the element, not on every attribute write.
 *
 * @param {Element} element the chart element or one of its ancestors
 * @param {() => void} callback
 * @returns {() => void} stops watching; call it in the controller's `disconnect()`
 */
export function onThemeChange(element, callback) {
    const root = element.ownerDocument?.documentElement;
    if (!root || typeof MutationObserver === 'undefined') {
        return () => {};
    }

    let dark = isDarkTheme(element);
    const observer = new MutationObserver(() => {
        const nowDark = isDarkTheme(element);
        if (nowDark !== dark) {
            dark = nowDark;
            callback();
        }
    });
    observer.observe(root, { attributes: true, attributeFilter: ['data-bs-theme'], subtree: true });

    return () => observer.disconnect();
}
