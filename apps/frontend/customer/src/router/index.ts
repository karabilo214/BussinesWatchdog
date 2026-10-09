import { createRouter, createWebHistory, type RouteLocationNormalized, type Router } from 'vue-router';
import { useSession } from '@/composables/useSession';

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean;
  }
}

export function buildRouter(): Router {
  const router = createRouter({
    history: createWebHistory('/app/'),
    routes: [
      { path: '/', redirect: { name: 'overview' } },
      { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
      { path: '/overview', name: 'overview', component: () => import('@/views/OverviewView.vue') },
      { path: '/stores/new', name: 'store-create', component: () => import('@/views/StoreCreateView.vue') },
      { path: '/stores/:id', name: 'store', component: () => import('@/views/StoreView.vue'), props: true },
      { path: '/incidents', name: 'incidents', component: () => import('@/views/IncidentsView.vue') },
      { path: '/incidents/:id', name: 'incident', component: () => import('@/views/IncidentView.vue'), props: true },
      { path: '/:pathMatch(.*)*', redirect: { name: 'overview' } },
    ],
  });

  router.beforeEach(async (to: RouteLocationNormalized) => {
    const { load } = useSession();
    const session = await load().catch(() => null);

    if (!to.meta.public && session === null) {
      return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } };
    }

    if (to.name === 'login' && session !== null) {
      return { name: 'overview' };
    }

    return true;
  });

  return router;
}
