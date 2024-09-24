import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);

import ChartClickController from './controllers/chart_click_controller.js';
app.register('chart-click', ChartClickController);
