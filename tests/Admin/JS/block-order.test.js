// Tests of the page block order helper (#967). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { orderByPositions } from '../../../assets/page-editor/block-order.js';

describe('orderByPositions', () => {
    it('orders items by their submitted positions', () => {
        assert.deepEqual(orderByPositions(['a', 'b', 'c'], ['2', '0', '1']), ['b', 'c', 'a']);
    });

    it('keeps the order without positions and puts unpositioned items last', () => {
        assert.deepEqual(orderByPositions(['a', 'b'], ['', '']), ['a', 'b']);
        assert.deepEqual(orderByPositions(['a', 'b', 'c'], ['', '0', 'x']), ['b', 'a', 'c']);
    });
});
