const MONTH_KEY_PATTERN = /^(\d{4})-(\d{2})$/;
const DAY_KEY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;

/** @type {Map<string, Intl.DateTimeFormat>} */
const monthFormattersByLocale = new Map();
/** @type {Map<string, Intl.DateTimeFormat>} */
const dayFormattersByLocale = new Map();

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
function getMonthFormatter(locale) {
    let formatter = monthFormattersByLocale.get(locale);
    if (!formatter) {
        formatter = new Intl.DateTimeFormat(locale, {
            month: 'short',
            year: '2-digit',
        });
        monthFormattersByLocale.set(locale, formatter);
    }

    return formatter;
}

/**
 * @param {string} locale
 * @returns {Intl.DateTimeFormat}
 */
function getDayFormatter(locale) {
    let formatter = dayFormattersByLocale.get(locale);
    if (!formatter) {
        formatter = new Intl.DateTimeFormat(locale, {
            day: '2-digit',
            month: '2-digit',
        });
        dayFormattersByLocale.set(locale, formatter);
    }

    return formatter;
}

/**
 * Formats a chart category (`YYYY-MM` or `YYYY-MM-DD`) for axis ticks and tooltips.
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

    const resolvedLocale = resolveLocale(locale);
    const dayMatch = DAY_KEY_PATTERN.exec(value);
    if (dayMatch) {
        const year = Number(dayMatch[1]);
        const month = Number(dayMatch[2]);
        const day = Number(dayMatch[3]);
        if (!Number.isInteger(year) || month < 1 || month > 12 || day < 1 || day > 31) {
            return value;
        }

        return getDayFormatter(resolvedLocale).format(new Date(year, month - 1, day));
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

    return getMonthFormatter(resolvedLocale).format(date);
}
