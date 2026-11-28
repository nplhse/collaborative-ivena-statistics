/** Minimal entrypoint for TwigBundle error pages (no Turbo/Stimulus/cookies). */
import '@tabler/core/dist/css/tabler.min.css';
import '@tabler/core';
import './styles/footer.css';

// "Back" goes to the previous page; without history (or JavaScript) the link leads home. Replaces a javascript: URL,
// which the Content Security Policy blocks (#580).
document.addEventListener('click', (event) => {
    const link =
        event.target instanceof Element ? event.target.closest('[data-history-back]') : null;
    if (link && window.history.length > 1) {
        event.preventDefault();
        window.history.back();
    }
});
