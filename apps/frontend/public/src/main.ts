import './styles/main.css';
import { ViteSSG } from 'vite-ssg';
import App from './App.vue';
import { localizationFor } from './i18n';
import { routes } from './routes';

export const createApp = ViteSSG(App, { routes, base: import.meta.env.BASE_URL }, ({ app, routePath, isClient }) => {
  app.use(localizationFor(isClient ? window.location.pathname : (routePath ?? '/')));
});
