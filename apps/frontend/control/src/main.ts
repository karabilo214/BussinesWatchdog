import './styles/main.css';
import { createApp } from 'vue';
import { configureCsrf, onUnauthorized } from '@bw/api-client';
import App from './App.vue';
import { useStaff } from './composables/useStaff';
import { localization } from './i18n';
import { buildRouter } from './router';

configureCsrf({ cookie: 'BW-PLATFORM-XSRF', endpoint: '/platform-api/v1/csrf-cookie' });

const router = buildRouter();

onUnauthorized(() => {
  const { me, forget } = useStaff();

  if (me.value !== null) {
    forget();
    void router.push({ name: 'login', query: { expired: '1' } });
  }
});

createApp(App).use(localization).use(router).mount('#app');
