import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();

// Lets the Symfony UX Inspector (dev only) read controller details. Debug mode is
// off in production, so the application is never exposed there.
if (app.debug) {
    window.Stimulus = app;
}
