import { startStimulusApp } from '@symfony/stimulus-bundle';

const app = startStimulusApp();

// Register local controllers
import ChartClickController from './controllers/chart_click_controller.js';
app.register('chart-click', ChartClickController);

import SelectMonthController from './controllers/select_month_controller.js';
app.register('select-month', SelectMonthController);

import MobileMenuController from './controllers/mobile_menu_controller.js';
app.register('mobile-menu', MobileMenuController);

import UserMenuController from './controllers/user_menu_controller.js';
app.register('user-menu', UserMenuController);

import CloseableController from './controllers/closeable_controller.js';
app.register('closeable', CloseableController);

import DocMenuItemController from './controllers/doc_menu_item_controller.js';
app.register('doc-menu-item', DocMenuItemController);

import RecaptchaController from './controllers/recaptcha_controller.js';
app.register('recaptcha', RecaptchaController);

import SubscriptionTableModalController from './controllers/subscription_table_modal_controller.js';
app.register('subscription-table-modal', SubscriptionTableModalController);

import CsrfProtectionController from './controllers/csrf_protection_controller.js';
app.register('csrf-protection', CsrfProtectionController);
