const MONTH_KEY_PATTERN = /^(\d{4})-(\d{2})$/;

/** @type {Map<string, Intl.DateTimeFormat>} */
const formattersByLocale = new Map();

/**
 * @param {string} [locale]
 * @returns {string}
 */
function resolveLocale(locale) {
    if (typeof locale === 'string' && locale !== '') {
        return locale;
    }

    if (typeof document !== 'undefined') {
        const lang = document.documentElement?.lang;
        if (typeof lang === 'string' && lang !== '') {
            return lang;
        }
    }

    return 'en';
}

/**
 * @param {string} locale
 * @returns {Intl.DateTimeFormat}
 */
function getFormatter(locale) {
    let formatter = formattersByLocale.get(locale);
    if (!formatter) {
        formatter = new Intl.DateTimeFormat(locale, {
            month: 'short',
            year: '2-digit',
        });
        formattersByLocale.set(locale, formatter);
    }

    return formatter;
}

/**
 * Formats a chart month category (`YYYY-MM`) for axis ticks and tooltips.
 * Non-matching values are returned unchanged.
 *
 * @param {unknown} value
 * @param {string} [locale]
 * @returns {unknown}
 */
export function formatChartMonthLabel(value, locale) {
    if (typeof value !== 'string') {
        return value;
    }

    const match = MONTH_KEY_PATTERN.exec(value);
    if (!match) {
        return value;
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    if (!Number.isInteger(year) || month < 1 || month > 12) {
        return value;
    }

    // Local calendar date is enough: we only format month + year.
    const date = new Date(year, month - 1, 1);

    return getFormatter(resolveLocale(locale)).format(date);
}
