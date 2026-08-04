import { Application } from '@hotwired/stimulus';
import AdminKpiChartController from './controllers/admin-kpi-chart_controller.js';
import CheckboxSelectAllController from './controllers/checkbox-select-all_controller.js';
import ConfirmSubmitController from './controllers/confirm-submit_controller.js';

const application = Application.start();
application.register('admin-kpi-chart', AdminKpiChartController);
application.register('checkbox-select-all', CheckboxSelectAllController);
application.register('confirm-submit', ConfirmSubmitController);
