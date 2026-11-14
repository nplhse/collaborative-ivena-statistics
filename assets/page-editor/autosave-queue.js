// Autosave scheduling for the page block editor (#967, ADR 036).
//
// Changes are saved after `delay` ms without further changes, one request at a time. Changes made while a request
// runs are saved by the next request. A failed save keeps the changes pending until `retry()`; a conflict (the
// server has a newer revision) stops autosaving, because saving again would overwrite newer input.

export const State = Object.freeze({
    IDLE: 'idle',
    UNSAVED: 'unsaved',
    SAVING: 'saving',
    SAVED: 'saved',
    FAILED: 'failed',
    CONFLICT: 'conflict',
});

export class AutosaveQueue {
    /**
     * @param {object}   options
     * @param {number}   options.delay       idle time before a save, in ms
     * @param {Function} options.send        async () => 'ok' | 'conflict'; throws on failure
     * @param {Function} options.onState     (state) => void
     * @param {Function} [options.setTimer]  defaults to setTimeout
     * @param {Function} [options.clearTimer] defaults to clearTimeout
     */
    constructor({
        delay,
        send,
        onState,
        setTimer = (callback, ms) => setTimeout(callback, ms),
        clearTimer = (timer) => clearTimeout(timer),
    }) {
        this.delay = delay;
        this.send = send;
        this.onState = onState;
        this.setTimer = setTimer;
        this.clearTimer = clearTimer;
        this.timer = null;
        this.dirty = false;
        this.inFlight = null;
        this.state = State.IDLE;
    }

    /** True while changes are not saved yet or a save is running. */
    get pending() {
        return this.dirty || null !== this.inFlight;
    }

    change() {
        if (State.CONFLICT === this.state) {
            // Not saved anywhere: still counts as pending, so leaving the page warns.
            this.dirty = true;

            return;
        }

        this.dirty = true;
        this.setState(State.UNSAVED);
        this.schedule();
    }

    /** Saves now, e.g. after a failed save or before an explicit save; resolves when nothing is pending. */
    async retry() {
        this.cancelTimer();
        await this.run();
    }

    /** Resolves once no request is running and nothing is left to save (or saving failed). */
    async settle() {
        while (null !== this.inFlight) {
            await this.inFlight;
        }
    }

    schedule() {
        this.cancelTimer();
        this.timer = this.setTimer(() => {
            this.timer = null;
            this.run();
        }, this.delay);
    }

    cancelTimer() {
        if (null !== this.timer) {
            this.clearTimer(this.timer);
            this.timer = null;
        }
    }

    async run() {
        if (null !== this.inFlight) {
            await this.inFlight;

            return this.dirty && State.FAILED !== this.state ? this.run() : undefined;
        }

        if (!this.dirty || State.CONFLICT === this.state) {
            return undefined;
        }

        this.dirty = false;
        this.setState(State.SAVING);
        this.inFlight = (async () => {
            try {
                const result = await this.send();
                if ('conflict' === result) {
                    this.setState(State.CONFLICT);
                } else if (this.dirty) {
                    this.setState(State.UNSAVED);
                    this.schedule();
                } else {
                    this.setState(State.SAVED);
                }
            } catch (_error) {
                this.dirty = true;
                this.setState(State.FAILED);
            } finally {
                this.inFlight = null;
            }
        })();

        return this.inFlight;
    }

    setState(state) {
        this.state = state;
        this.onState(state);
    }
}
