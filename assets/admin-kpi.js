import { Application } from '@hotwired/stimulus';
import AdminKpiChartController from './controllers/admin-kpi-chart_controller.js';
import CheckboxSelectAllController from './controllers/checkbox-select-all_controller.js';
import ConfirmSubmitController from './controllers/confirm-submit_controller.js';
import CopyToClipboardController from './controllers/copy-to-clipboard_controller.js';
import VichFilePreviewController from './controllers/vich-file-preview_controller.js';
import './styles/admin-kpi.css';

const application = Application.start();
application.register('admin-kpi-chart', AdminKpiChartController);
application.register('checkbox-select-all', CheckboxSelectAllController);
application.register('confirm-submit', ConfirmSubmitController);
application.register('copy-to-clipboard', CopyToClipboardController);
application.register('vich-file-preview', VichFilePreviewController);
