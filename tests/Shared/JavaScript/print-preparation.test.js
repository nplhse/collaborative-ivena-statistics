import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    fitChartsToPaper,
    loadPendingFrames,
    restoreChartsAfterPrint,
    updatePrintTime,
} from '../../../assets/lib/print-preparation.js';

class FakeElement {
    constructor(attributes = {}, extra = {}) {
        this.attributes = new Map(Object.entries(attributes));
        Object.assign(this, extra);
    }

    hasAttribute(name) {
        return this.attributes.has(name);
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }
}

const rootWith = (elements) => ({ querySelectorAll: () => elements });

test('gives a chart a viewBox from its drawn size and removes it again', () => {
    const svg = new FakeElement({ width: '1200', height: '300.5' });
    const root = rootWith([svg]);

    fitChartsToPaper(root);
    assert.equal(svg.getAttribute('viewBox'), '0 0 1200 300.5');

    restoreChartsAfterPrint(root);
    assert.equal(svg.hasAttribute('viewBox'), false);
    assert.equal(svg.hasAttribute('data-print-viewbox'), false);
});

test('leaves relative sizes and an existing viewBox alone', () => {
    const relative = new FakeElement({ width: '100%', height: '300' });
    const existing = new FakeElement({ width: '200', height: '100', viewBox: '0 0 20 10' });

    fitChartsToPaper(rootWith([relative, existing]));

    assert.equal(relative.hasAttribute('viewBox'), false);
    assert.equal(existing.getAttribute('viewBox'), '0 0 20 10');
    assert.equal(existing.hasAttribute('data-print-viewbox'), false);
});

test('writes the moment of printing', () => {
    const time = new FakeElement();

    updatePrintTime(rootWith([time]), new Date(Date.UTC(2026, 10, 23, 12, 30)), 'de-DE');

    assert.match(time.textContent, /23\.11\.2026/);
});

test('switches lazy frames to eager and waits until they loaded', async () => {
    let finish;
    const frame = new FakeElement(
        { loading: 'lazy' },
        { loaded: new Promise((resolve) => (finish = resolve)) },
    );
    let done = false;

    const pending = loadPendingFrames(rootWith([frame]), 1000).then(() => (done = true));
    await Promise.resolve();

    assert.equal(frame.getAttribute('loading'), 'eager');
    assert.equal(done, false);

    finish();
    await pending;
    assert.equal(done, true);
});

test('still prints when a frame does not answer', async () => {
    const frame = new FakeElement({ loading: 'lazy' }, { loaded: new Promise(() => {}) });

    await loadPendingFrames(rootWith([frame]), 5);

    assert.equal(frame.getAttribute('loading'), 'eager');
});
