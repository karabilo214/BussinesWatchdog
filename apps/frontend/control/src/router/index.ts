import { createRouter, createWebHistory, type Router } from 'vue-router';
import SectionView from '@/views/SectionView.vue';

export function buildRouter(): Router {
  return createRouter({
    history: createWebHistory('/'),
    routes: [
      { path: '/', redirect: { name: 'owner' } },
      { path: '/owner', name: 'owner', component: SectionView, props: { section: 'owner' } },
      { path: '/admin', name: 'admin', component: SectionView, props: { section: 'admin' } },
      { path: '/:pathMatch(.*)*', redirect: { name: 'owner' } },
    ],
  });
}
