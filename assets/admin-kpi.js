import { Application } from '@hotwired/stimulus';
import AccessTrendChartController from './controllers/access-trend-chart_controller.js';
import AdminKpiChartController from './controllers/admin-kpi-chart_controller.js';
import AuditNewEntriesController from './controllers/audit-new-entries_controller.js';
import CheckboxSelectAllController from './controllers/checkbox-select-all_controller.js';
import ConfirmSubmitController from './controllers/confirm-submit_controller.js';
import CopyToClipboardController from './controllers/copy-to-clipboard_controller.js';
import FileInputPreviewController from './controllers/file-input-preview_controller.js';
import MessengerHistoryChartController from './controllers/messenger-history-chart_controller.js';
import NavigationInspectorController from './controllers/navigation-inspector_controller.js';
import StorageHistoryChartController from './controllers/storage-history-chart_controller.js';
import TimelineDetailsController from './controllers/timeline-details_controller.js';
import UsageTrendChartController from './controllers/usage-trend-chart_controller.js';
import './styles/admin-kpi.css';
import './styles/admin-navigation-inspector.css';
import './styles/charts.css';

const application = Application.start();
application.register('access-trend-chart', AccessTrendChartController);
application.register('admin-kpi-chart', AdminKpiChartController);
application.register('audit-new-entries', AuditNewEntriesController);
application.register('checkbox-select-all', CheckboxSelectAllController);
application.register('confirm-submit', ConfirmSubmitController);
application.register('copy-to-clipboard', CopyToClipboardController);
application.register('file-input-preview', FileInputPreviewController);
application.register('messenger-history-chart', MessengerHistoryChartController);
application.register('navigation-inspector', NavigationInspectorController);
application.register('storage-history-chart', StorageHistoryChartController);
application.register('timeline-details', TimelineDetailsController);
application.register('usage-trend-chart', UsageTrendChartController);

// Lets the Symfony UX Inspector read controller details in the admin area. The
// <ux-inspector> element is only injected in the dev environment with debug on.
if (document.getElementById('ux-inspector')) {
    window.Stimulus = application;
}
