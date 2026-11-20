// Batching of the lazily loaded encounter details in the Explore allocation list (#1203). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    createDetailBatcher,
    MAX_IDS_PER_REQUEST,
} from '../../../assets/js/encounter/listDetailBatch.js';

function manualSchedule() {
    const callbacks = [];
    return {
        schedule: (callback) => callbacks.push(callback),
        run: () => callbacks.splice(0).forEach((callback) => callback()),
    };
}

describe('encounter list detail batch', () => {
    it('loads the cells of one tick with one request and answers each cell', async () => {
        const calls = [];
        const { schedule, run } = manualSchedule();
        const request = createDetailBatcher(async (url, ids) => {
            calls.push([url, ids]);
            return { 7: '<b>red</b>' };
        }, schedule);

        const first = request('/details', '7');
        const second = request('/details', '8');
        const again = request('/details', '7');
        run();

        assert.deepEqual(calls, [['/details', ['7', '8']]]);
        assert.equal(await first, '<b>red</b>');
        assert.equal(await again, '<b>red</b>');
        assert.equal(await second, null);
    });

    it('splits more ids than one request may carry', async () => {
        const sizes = [];
        const { schedule, run } = manualSchedule();
        const request = createDetailBatcher(async (url, ids) => {
            sizes.push(ids.length);
            return {};
        }, schedule);

        const all = Array.from({ length: MAX_IDS_PER_REQUEST + 1 }, (_, index) =>
            request('/details', String(index + 1)),
        );
        run();
        await Promise.all(all);

        assert.deepEqual(sizes, [MAX_IDS_PER_REQUEST, 1]);
    });

    it('answers null when the request fails, so the placeholder can go', async () => {
        const { schedule, run } = manualSchedule();
        const request = createDetailBatcher(async () => {
            throw new Error('offline');
        }, schedule);

        const cell = request('/details', '3');
        run();

        assert.equal(await cell, null);
    });

    it('starts a new request for cells of a later tick', async () => {
        let requests = 0;
        const { schedule, run } = manualSchedule();
        const request = createDetailBatcher(async () => {
            requests += 1;
            return {};
        }, schedule);

        const first = request('/details', '1');
        run();
        await first;
        const later = request('/details', '2');
        run();
        await later;

        assert.equal(requests, 2);
    });
});
