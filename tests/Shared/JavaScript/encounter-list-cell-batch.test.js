// Batching of the lazily loaded encounter schema cells in the Explore allocation list (#1203). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    createCellBatcher,
    MAX_IDS_PER_REQUEST,
} from '../../../assets/js/encounter/listCellBatch.js';

function manualSchedule() {
    const callbacks = [];
    return {
        schedule: (callback) => callbacks.push(callback),
        run: () => callbacks.splice(0).forEach((callback) => callback()),
    };
}

describe('encounter list cell batch', () => {
    it('loads every cell of one tick, all ids and columns, with one request', async () => {
        const calls = [];
        const { schedule, run } = manualSchedule();
        const request = createCellBatcher(async (url, ids, columns) => {
            calls.push([url, ids, columns]);
            return { 7: { category: '<b>red</b>', diagram: 'Chest pain' } };
        }, schedule);

        const category = request('/cells', '7', 'category');
        const diagram = request('/cells', '7', 'diagram');
        const twice = request('/cells', '7', 'category');
        const other = request('/cells', '8', 'category');
        run();

        assert.deepEqual(calls, [['/cells', ['7', '8'], ['category', 'diagram']]]);
        assert.equal(await category, '<b>red</b>');
        assert.equal(await twice, '<b>red</b>');
        assert.equal(await diagram, 'Chest pain');
        assert.equal(await other, null);
    });

    it('splits more ids than one request may carry', async () => {
        const sizes = [];
        const { schedule, run } = manualSchedule();
        const request = createCellBatcher(async (url, ids) => {
            sizes.push(ids.length);
            return {};
        }, schedule);

        const all = Array.from({ length: MAX_IDS_PER_REQUEST + 1 }, (_, index) =>
            request('/cells', String(index + 1), 'category'),
        );
        run();
        await Promise.all(all);

        assert.deepEqual(sizes, [MAX_IDS_PER_REQUEST, 1]);
    });

    it('answers null when the request fails, so the placeholder can go', async () => {
        const { schedule, run } = manualSchedule();
        const request = createCellBatcher(async () => {
            throw new Error('offline');
        }, schedule);

        const cell = request('/cells', '3', 'category');
        run();

        assert.equal(await cell, null);
    });

    it('starts a new request for cells of a later tick', async () => {
        let requests = 0;
        const { schedule, run } = manualSchedule();
        const request = createCellBatcher(async () => {
            requests += 1;
            return {};
        }, schedule);

        const first = request('/cells', '1', 'category');
        run();
        await first;
        const later = request('/cells', '2', 'category');
        run();
        await later;

        assert.equal(requests, 2);
    });
});
