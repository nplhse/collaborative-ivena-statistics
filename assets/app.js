import './bootstrap.js';
import { installPrintPreparation } from './lib/print-preparation.js';

import './styles/app.css';
import './styles/pages/content-blocks.css';
import './styles/pages/page-navigation.css';
import './styles/pages/analytics-library.css';

import '@tabler/core/dist/css/tabler.min.css';
import '@tabler/core';

import './styles/footer.css';
import './styles/navigation.css';
import './styles/feedback-widget.css';
import './styles/charts.css';
import './styles/reactions.css';
import './styles/print.css';

installPrintPreparation(window, document);
