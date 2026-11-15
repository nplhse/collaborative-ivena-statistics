// Tests of the chart theme detection (#1260). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { isDarkTheme } from '../../../assets/lib/chart-theme.js';

// Minimal element stub: `closest('[data-bs-theme]')` walks up to the first node with the attribute.
const node = (theme, parent = null) => ({
    parent,
    getAttribute: (name) => (name === 'data-bs-theme' ? theme : null),
    closest(selector) {
        assert.equal(selector, '[data-bs-theme]');
        for (let current = this; current; current = current.parent) {
            if (current.getAttribute('data-bs-theme') !== null) {
                return current;
            }
        }
        return null;
    },
});

describe('isDarkTheme', () => {
    it('reads the theme from <html> on app pages', () => {
        const chart = node(null, node(null, node('dark')));
        assert.equal(isDarkTheme(chart), true);
    });

    it('reads the theme from <body> on EasyAdmin pages', () => {
        const chart = node(null, node('dark', node(null)));
        assert.equal(isDarkTheme(chart), true);
    });

    it('uses the nearest attribute when several are set', () => {
        const chart = node(null, node('light', node('dark')));
        assert.equal(isDarkTheme(chart), false);
    });

    it('is light when the theme is light', () => {
        const chart = node(null, node(null, node('light')));
        assert.equal(isDarkTheme(chart), false);
    });

    it('is light when no theme attribute is set', () => {
        const chart = node(null, node(null, node(null)));
        assert.equal(isDarkTheme(chart), false);
    });
});
