import { createRouter, createWebHistory, type Router } from 'vue-router';
import { useStaff } from '@/composables/useStaff';

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean;
  }
}

export const OWNER_SECTIONS = ['overview', 'tenants', 'subscriptions', 'payments', 'plans', 'service', 'staff', 'audit', 'settings'] as const;
export type OwnerSection = (typeof OWNER_SECTIONS)[number];

export function buildRouter(): Router {
  const router = createRouter({
    history: createWebHistory('/'),
    routes: [
      { path: '/', redirect: { name: 'owner-overview' } },
      { path: '/owner/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
      ...OWNER_SECTIONS.map((section) => ({
        path: section === 'overview' ? '/owner' : `/owner/${section}`,
        name: `owner-${section}`,
        component: () => import('@/views/SectionView.vue'),
        props: { section },
      })),
      { path: '/admin', redirect: { name: 'owner-overview' } },
      { path: '/:pathMatch(.*)*', redirect: { name: 'owner-overview' } },
    ],
  });

  router.beforeEach(async (to) => {
    const { load } = useStaff();
    const me = await load().catch(() => null);

    if (!to.meta.public && me === null) {
      return { name: 'login', query: to.fullPath === '/owner' ? {} : { redirect: to.fullPath } };
    }

    if (to.name === 'login' && me !== null) {
      return { name: 'owner-overview' };
    }

    return true;
  });

  return router;
}
