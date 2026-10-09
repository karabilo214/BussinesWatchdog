import './styles/main.css';
import { createApp } from 'vue';
import App from './App.vue';
import { localization } from './i18n';
import { buildRouter } from './router';

createApp(App).use(localization).use(buildRouter()).mount('#app');
