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
