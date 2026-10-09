import './styles/main.css';
import { createApp } from 'vue';
import App from './App.vue';
import { onUnauthorized } from '@bw/api-client';
import { useSession } from './composables/useSession';
import { localization } from './i18n';
import { buildRouter } from './router';

const router = buildRouter();

onUnauthorized(() => {
  const { session, clear } = useSession();

  if (session.value !== null) {
    clear();
    void router.push({ name: 'login', query: { expired: '1' } });
  }
});

createApp(App).use(localization).use(router).mount('#app');
