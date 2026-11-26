// Tests of the chart theme detection (#1260). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { isDarkTheme, onThemeChange } from '../../../assets/lib/chart-theme.js';

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

describe('onThemeChange', () => {
    // MutationObserver stub: records what is observed and lets the test trigger a mutation.
    const installObserver = () => {
        const observers = [];
        globalThis.MutationObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.active = false;
                observers.push(this);
            }
            observe(target, options) {
                this.target = target;
                this.options = options;
                this.active = true;
            }
            disconnect() {
                this.active = false;
            }
        };
        const mutate = () => observers.filter((o) => o.active).forEach((o) => o.callback([]));

        return { observers, mutate };
    };

    // <html data-bs-theme> with a chart element below it; the document root is the <html> node.
    const page = (theme) => {
        const html = node(theme);
        const chart = node(null, node(null, html));
        chart.ownerDocument = { documentElement: html };
        let current = theme;
        html.getAttribute = (name) => (name === 'data-bs-theme' ? current : null);

        return { chart, html, setTheme: (value) => (current = value) };
    };

    it('watches the theme attribute on the whole document', () => {
        const { observers } = installObserver();
        const { chart, html } = page('light');

        onThemeChange(chart, () => {});

        assert.equal(observers.length, 1);
        assert.equal(observers[0].target, html);
        assert.deepEqual(observers[0].options, {
            attributes: true,
            attributeFilter: ['data-bs-theme'],
            subtree: true,
        });
    });

    it('calls back when the scheme switches, in both directions', () => {
        const { mutate } = installObserver();
        const { chart, setTheme } = page('light');
        let calls = 0;
        onThemeChange(chart, () => calls++);

        setTheme('dark');
        mutate();
        setTheme('light');
        mutate();

        assert.equal(calls, 2);
    });

    it('does not call back when the attribute is written without a change', () => {
        const { mutate } = installObserver();
        const { chart } = page('dark');
        let calls = 0;
        onThemeChange(chart, () => calls++);

        mutate();

        assert.equal(calls, 0);
    });

    it('does not call back after it was stopped', () => {
        const { mutate } = installObserver();
        const { chart, setTheme } = page('light');
        let calls = 0;
        const stop = onThemeChange(chart, () => calls++);

        stop();
        setTheme('dark');
        mutate();

        assert.equal(calls, 0);
    });

    it('returns a no-op without MutationObserver', () => {
        delete globalThis.MutationObserver;
        const { chart } = page('light');

        const stop = onThemeChange(chart, () => assert.fail('no callback expected'));

        assert.equal(typeof stop, 'function');
        stop();
    });
});
