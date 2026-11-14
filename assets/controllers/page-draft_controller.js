import { Controller } from '@hotwired/stimulus';
import { AutosaveQueue, State } from '../page-editor/autosave-queue.js';

const AUTOSAVE_DELAY_MS = 2000;
const CHANGE_EVENTS = ['input', 'change', 'trix-change'];
const STRUCTURE_EVENTS = [
    'ea.collection.item-added',
    'ea.collection.item-removed',
    'collection-reorder:moved',
];

/*
 * Draft autosave for the page block editor (#967, ADR 036).
 *
 * Saves the content blocks as a draft after a short pause, shows the save state, keeps the input on failures with a
 * retry, warns before leaving with unsaved changes and coordinates with the explicit save: that one waits for a
 * running autosave and sends the draft revision it is based on.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        url: String,
        discardUrl: String,
        token: String,
        revision: Number,
        revisionField: String,
        hasDraft: Boolean,
        savedAt: String,
        labels: Object,
    };

    connect() {
        this.form = this.element.closest('form');
        if (!this.form || !this.urlValue) {
            return;
        }

        this.queue = new AutosaveQueue({
            delay: AUTOSAVE_DELAY_MS,
            send: () => this.sendDraft(),
            onState: (state) => this.render(state),
        });

        this.onContentChange = this.onContentChange.bind(this);
        this.onStructureChange = this.onStructureChange.bind(this);
        this.onSubmit = this.onSubmit.bind(this);
        this.onBeforeUnload = this.onBeforeUnload.bind(this);

        CHANGE_EVENTS.forEach((type) => this.element.addEventListener(type, this.onContentChange));
        STRUCTURE_EVENTS.forEach((type) => document.addEventListener(type, this.onStructureChange));
        this.form.addEventListener('submit', this.onSubmit);
        window.addEventListener('beforeunload', this.onBeforeUnload);

        this.buildStatus();
        this.renderInitial();
    }

    disconnect() {
        CHANGE_EVENTS.forEach((type) =>
            this.element.removeEventListener(type, this.onContentChange),
        );
        STRUCTURE_EVENTS.forEach((type) =>
            document.removeEventListener(type, this.onStructureChange),
        );
        this.form?.removeEventListener('submit', this.onSubmit);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        this.queue?.cancelTimer();
        this.status?.remove();
    }

    onContentChange(event) {
        // Hidden bookkeeping fields (positions, type-change markers) do not change content on their own.
        if (event.target instanceof HTMLInputElement && 'hidden' === event.target.type) {
            return;
        }

        this.queue.change();
    }

    onStructureChange(event) {
        if (
            this.element.contains(event.target) ||
            this.element.contains(event.detail?.collection ?? null)
        ) {
            this.queue.change();
        }
    }

    /** The explicit save waits for a running autosave and sends the revision it is based on. */
    onSubmit(event) {
        if (this.submitting) {
            return;
        }

        this.queue.cancelTimer();
        if (null !== this.queue.inFlight) {
            event.preventDefault();
            const submitter = event.submitter;
            this.queue.settle().then(() => this.submitForm(submitter));

            return;
        }

        this.submitForm(null, event);
    }

    submitForm(submitter, event = null) {
        this.submitting = true;
        this.revisionInput().value = String(this.revisionValue);
        if (null === event) {
            // EasyAdmin disabled all submit buttons on the deferred submit; a disabled submitter would not send
            // which save action was chosen.
            Array.from(this.form.elements)
                .filter((element) => 'submit' === element.type)
                .forEach((button) => button.removeAttribute('disabled'));
            this.form.requestSubmit(submitter ?? undefined);
        }
    }

    onBeforeUnload(event) {
        if (this.submitting || !this.queue.pending) {
            return;
        }

        event.preventDefault();
        event.returnValue = this.labelsValue.leave_warning ?? '';
    }

    revisionInput() {
        let input = this.form.querySelector(`input[name="${CSS.escape(this.revisionFieldValue)}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = this.revisionFieldValue;
            this.form.append(input);
        }

        return input;
    }

    async sendDraft() {
        const body = new URLSearchParams();
        new FormData(this.form).forEach((value, name) => {
            if ('string' === typeof value && name.startsWith(`${this.contentName()}[`)) {
                body.append(name, value);
            }
        });
        body.append('revision', String(this.revisionValue));
        body.append('_token', this.tokenValue);

        const response = await fetch(this.urlValue, {
            method: 'POST',
            body,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (409 === response.status) {
            return 'conflict';
        }

        if (!response.ok) {
            throw new Error(`Draft autosave failed with HTTP ${response.status}`);
        }

        const result = await response.json();
        this.revisionValue = result.revision;
        this.savedAtValue = result.savedAt ?? '';
        this.hasDraftValue = true;

        return 'ok';
    }

    /** Full name of the content collection, e.g. `PageTranslation[content]`. */
    contentName() {
        const field = this.element.querySelector('[name$="][type]"]');
        const match = field?.name.match(/^(.+\[content\])\[/);

        return match ? match[1] : 'PageTranslation[content]';
    }

    async discard() {
        if (!window.confirm(this.labelsValue.discard_confirm ?? '')) {
            return;
        }

        this.queue.cancelTimer();
        await this.queue.settle();

        const body = new URLSearchParams({
            revision: String(this.revisionValue),
            _token: this.tokenValue,
        });
        const response = await fetch(this.discardUrlValue, {
            method: 'POST',
            body,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            this.render(409 === response.status ? State.CONFLICT : State.FAILED);

            return;
        }

        this.submitting = true;
        window.location.reload();
    }

    buildStatus() {
        this.status = document.createElement('div');
        this.status.className =
            'page-draft-status alert alert-secondary d-flex flex-wrap align-items-center gap-2 py-2';
        this.status.setAttribute('role', 'status');
        this.status.setAttribute('aria-live', 'polite');

        this.statusText = document.createElement('span');
        this.retryButton = this.createButton(this.labelsValue.retry, () => this.queue.retry());
        this.discardButton = this.createButton(this.labelsValue.discard, () => this.discard());

        this.status.append(this.statusText, this.retryButton, this.discardButton);

        // Next to the save buttons, where the state of the page is expected; inside the field as a fallback.
        const header = document.querySelector('.content-header');
        if (header) {
            header.after(this.status);
        } else {
            this.element.prepend(this.status);
        }
    }

    createButton(label, handler) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-secondary';
        button.textContent = label ?? '';
        button.hidden = true;
        button.addEventListener('click', handler);

        return button;
    }

    renderInitial() {
        if (this.hasDraftValue) {
            this.show('alert-warning', this.withTime(this.labelsValue.restored), { discard: true });
        } else {
            this.status.hidden = true;
        }
    }

    render(state) {
        switch (state) {
            case State.UNSAVED:
                this.show('alert-secondary', this.labelsValue.unsaved, {
                    discard: this.hasDraftValue,
                });
                break;
            case State.SAVING:
                this.show('alert-secondary', this.labelsValue.saving);
                break;
            case State.SAVED:
                this.show('alert-success', this.withTime(this.labelsValue.saved), {
                    discard: true,
                });
                break;
            case State.FAILED:
                this.show('alert-danger', this.labelsValue.failed, { retry: true });
                break;
            case State.CONFLICT:
                this.show('alert-danger', this.labelsValue.conflict);
                break;
            default:
                this.status.hidden = true;
        }
    }

    show(variant, text, { retry = false, discard = false } = {}) {
        this.status.hidden = false;
        this.status.classList.remove(
            'alert-secondary',
            'alert-success',
            'alert-warning',
            'alert-danger',
        );
        this.status.classList.add(variant);
        this.statusText.textContent = text ?? '';
        this.retryButton.hidden = !retry;
        this.discardButton.hidden = !discard;
    }

    withTime(pattern) {
        const savedAt = this.savedAtValue ? new Date(this.savedAtValue) : null;
        const time =
            savedAt && !Number.isNaN(savedAt.getTime())
                ? savedAt.toLocaleString(document.documentElement.lang || undefined, {
                      dateStyle: 'short',
                      timeStyle: 'short',
                  })
                : '';

        return (pattern ?? '').replace('{time}', time);
    }
}
