import { Application } from '@hotwired/stimulus';
import AdminKpiChartController from './controllers/admin-kpi-chart_controller.js';

const application = Application.start();
application.register('admin-kpi-chart', AdminKpiChartController);
