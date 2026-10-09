import '@fontsource/ibm-plex-sans/400.css';
import '@fontsource/ibm-plex-sans/500.css';
import '@fontsource/ibm-plex-sans/600.css';
import '@fontsource/ibm-plex-mono/400.css';
import './styles/main.css';
import { createApp } from 'vue';
import App from './App.vue';
import { onUnauthorized } from './api/http';
import { useSession } from './composables/useSession';
import { i18n, setLocale } from './i18n';
import { buildRouter } from './router';

const router = buildRouter();

onUnauthorized(() => {
  const { session, clear } = useSession();

  if (session.value !== null) {
    clear();
    void router.push({ name: 'login', query: { expired: '1' } });
  }
});

setLocale(i18n.global.locale.value);
createApp(App).use(i18n).use(router).mount('#app');
