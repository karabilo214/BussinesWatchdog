import type { RouteRecordRaw } from 'vue-router';

export const routes: RouteRecordRaw[] = [
  { path: '/', name: 'chooser', component: () => import('./views/LanguageChooser.vue') },
  { path: '/:locale(ru|en|de)', name: 'home', component: () => import('./views/HomeView.vue') },
];
