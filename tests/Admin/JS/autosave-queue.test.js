// Tests of the page editor autosave queue (#967). Run with `npm run test:js`.
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { AutosaveQueue, State } from '../../../assets/page-editor/autosave-queue.js';

/** Manual timer: `fire()` runs the scheduled callback. */
function manualTimer() {
    const timer = { callback: null };
    timer.set = (callback) => {
        timer.callback = callback;

        return 'timer';
    };
    timer.clear = () => {
        timer.callback = null;
    };
    timer.fire = () => {
        const callback = timer.callback;
        timer.callback = null;
        callback?.();
    };

    return timer;
}

/** A send function whose requests are resolved or rejected by the test. */
function controlledSend() {
    const calls = [];
    const send = () =>
        new Promise((resolve, reject) => {
            calls.push({ resolve, reject });
        });

    return { send, calls };
}

function createQueue(send) {
    const timer = manualTimer();
    const states = [];
    const queue = new AutosaveQueue({
        delay: 2000,
        send,
        onState: (state) => states.push(state),
        setTimer: timer.set,
        clearTimer: timer.clear,
    });

    return { queue, timer, states };
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

describe('AutosaveQueue', () => {
    it('saves once after the pause, not per change', async () => {
        const { send, calls } = controlledSend();
        const { queue, timer, states } = createQueue(send);

        queue.change();
        queue.change();
        assert.equal(calls.length, 0);
        assert.equal(queue.pending, true);

        timer.fire();
        assert.equal(calls.length, 1);
        calls[0].resolve('ok');
        await queue.settle();

        assert.deepEqual(states, [State.UNSAVED, State.UNSAVED, State.SAVING, State.SAVED]);
        assert.equal(queue.pending, false);
    });

    it('runs one request at a time and saves changes made meanwhile afterwards', async () => {
        const { send, calls } = controlledSend();
        const { queue, timer } = createQueue(send);

        queue.change();
        timer.fire();
        queue.change();
        timer.fire();
        await tick();
        assert.equal(calls.length, 1, 'second save waits for the first');

        calls[0].resolve('ok');
        await tick();
        await tick();
        assert.equal(calls.length, 2, 'changes made during the first save are saved next');
        calls[1].resolve('ok');
        await queue.settle();
        assert.equal(queue.state, State.SAVED);
    });

    it('keeps changes after a failure until retry', async () => {
        const { send, calls } = controlledSend();
        const { queue, timer } = createQueue(send);

        queue.change();
        timer.fire();
        calls[0].reject(new Error('offline'));
        await queue.settle();
        assert.equal(queue.state, State.FAILED);
        assert.equal(queue.pending, true);

        const retry = queue.retry();
        calls[1].resolve('ok');
        await retry;
        assert.equal(queue.state, State.SAVED);
        assert.equal(queue.pending, false);
    });

    it('stops after a conflict instead of overwriting newer input', async () => {
        const { send, calls } = controlledSend();
        const { queue, timer } = createQueue(send);

        queue.change();
        timer.fire();
        calls[0].resolve('conflict');
        await queue.settle();
        assert.equal(queue.state, State.CONFLICT);

        queue.change();
        timer.fire();
        await tick();
        assert.equal(calls.length, 1);
        assert.equal(queue.pending, true, 'unsaved input still warns before leaving');
    });
});
