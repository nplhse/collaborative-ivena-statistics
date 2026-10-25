import { Controller } from '@hotwired/stimulus';

/*
 * Shows notification toasts (ADR 016). Toasts arrive as a "notification:toasts" window event from the
 * notification-poll controller; texts are inserted as text, never as HTML. Ordinary toasts hide after a
 * while unless hovered or focused, important ones stay until they are closed.
 */
const HIDE_AFTER_MS = 10000;

export default class extends Controller {
    static targets = ['template'];

    show(event) {
        const { toasts = [], more = null } = event.detail ?? {};

        toasts.forEach((toast) => this.add(toast));

        if (more) {
            this.add({
                title: more.label,
                message: '',
                url: more.url,
                severity: 'secondary',
                important: false,
            });
        }
    }

    dismiss(event) {
        event.currentTarget.closest('.toast')?.remove();
    }

    add({ title, message, url, severity, important }) {
        const fragment = this.templateTarget.content.cloneNode(true);
        const toast = fragment.querySelector('.toast');

        toast.setAttribute('role', important ? 'alert' : 'status');
        toast.setAttribute('aria-live', important ? 'assertive' : 'polite');
        toast.querySelector('[data-role="dot"]').classList.add(`bg-${severity}`);
        toast.querySelector('[data-role="title"]').textContent = title;

        const body = toast.querySelector('[data-role="message"]');
        body.textContent = message;
        body.classList.toggle('d-none', message === '');

        if (url) {
            const link = toast.querySelector('[data-role="link"]');
            link.href = url;
            link.classList.remove('d-none');
            link.classList.add(`btn-${severity}`);
        }

        this.element.append(toast);

        if (!important) {
            this.autoHide(toast);
        }
    }

    autoHide(toast) {
        let timer = null;
        const start = () => {
            timer = window.setTimeout(() => toast.remove(), HIDE_AFTER_MS);
        };
        const stop = () => window.clearTimeout(timer);

        toast.addEventListener('mouseenter', stop);
        toast.addEventListener('focusin', stop);
        toast.addEventListener('mouseleave', start);
        toast.addEventListener('focusout', start);
        start();
    }
}
