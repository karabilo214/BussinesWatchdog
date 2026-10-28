import { createRouter, createWebHistory, type RouteLocationNormalized, type Router } from 'vue-router';
import type { TenantPermission } from '@/api/types';
import { useSession } from '@/composables/useSession';

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean;
    permission?: TenantPermission;
  }
}

/** Back in history the list loads asynchronously: wait (up to a second) until the page is tall enough to scroll to. */
function restoreWhenRendered(saved: { left: number; top: number }): Promise<{ left: number; top: number }> {
  return new Promise((resolve) => {
    const started = Date.now();
    const check = () => {
      const tallEnough = document.documentElement.scrollHeight >= saved.top + window.innerHeight;

      if (tallEnough || Date.now() - started > 1000) resolve(saved);
      else setTimeout(check, 50);
    };

    check();
  });
}

/** A linked block loads with its page and the panels above it: wait (up to three seconds) until it exists and the page stops growing, then keep it below the sticky header. */
function scrollWhenRendered(hash: string): Promise<{ el: string; top: number } | { top: number }> {
  return new Promise((resolve) => {
    const started = Date.now();
    let height = -1;
    let stableSince = 0;
    const check = () => {
      const found = document.getElementById(decodeURIComponent(hash.slice(1))) !== null;
      const current = document.documentElement.scrollHeight;
      const now = Date.now();

      if (current !== height) {
        height = current;
        stableSince = now;
      }

      const top = (document.querySelector<HTMLElement>('header.sticky')?.offsetHeight ?? 0) + 16;

      if (found && now - stableSince >= 300) resolve({ el: hash, top });
      else if (now - started > 3000) resolve(found ? { el: hash, top } : { top: 0 });
      else setTimeout(check, 50);
    };

    check();
  });
}

export function buildRouter(): Router {
  const router = createRouter({
    history: createWebHistory('/app/'),
    scrollBehavior: (to, from, saved) => {
      if (saved) return restoreWhenRendered(saved);
      if (to.hash) return scrollWhenRendered(to.hash);

      return to.path === from.path ? false : { top: 0 };
    },
    routes: [
      { path: '/', redirect: { name: 'overview' } },
      { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
      { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/ForgotPasswordView.vue'), meta: { public: true } },
      { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPasswordView.vue'), meta: { public: true } },
      { path: '/invitation', name: 'invitation', component: () => import('@/views/InvitationView.vue'), meta: { public: true } },
      { path: '/settings/profile', name: 'profile', component: () => import('@/views/ProfileView.vue') },
      { path: '/settings/team', name: 'team', component: () => import('@/views/TeamView.vue') },
      { path: '/settings/billing', name: 'billing', component: () => import('@/views/BillingView.vue') },
      { path: '/settings/organization', name: 'organization', component: () => import('@/views/OrganizationView.vue') },
      { path: '/settings/support', name: 'support', component: () => import('@/views/SupportGrantsView.vue'), meta: { permission: 'support.access' } },
      { path: '/overview', name: 'overview', component: () => import('@/views/OverviewView.vue') },
      { path: '/stores/new', name: 'store-create', component: () => import('@/views/StoreCreateView.vue') },
      { path: '/connect', name: 'plugin-connect', component: () => import('@/views/ConnectView.vue') },
      { path: '/stores/:id', name: 'store', component: () => import('@/views/StoreView.vue'), props: true },
      { path: '/incidents', name: 'incidents', component: () => import('@/views/IncidentsView.vue'), meta: { permission: 'incidents.read' } },
      { path: '/incidents/:id', name: 'incident', component: () => import('@/views/IncidentView.vue'), props: true, meta: { permission: 'incidents.read' } },
      { path: '/reconciliation', name: 'reconciliation', component: () => import('@/views/ReconciliationView.vue'), meta: { permission: 'reconciliation.read' } },
      { path: '/orders/:id', name: 'order', component: () => import('@/views/OrderView.vue'), props: true },
      { path: '/checks', name: 'checks', component: () => import('@/views/ChecksView.vue') },
      { path: '/checks/runs/:id', name: 'check-run', component: () => import('@/views/CheckRunView.vue'), props: true },
      { path: '/notifications', name: 'notifications', component: () => import('@/views/NotificationsView.vue') },
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

    const active = session?.memberships.find((membership) => membership.tenant_id === session.active_tenant_id);

    if (!to.meta.public && active?.tenant_status === 'deleting' && !['organization', 'profile'].includes(String(to.name))) {
      return { name: 'organization' };
    }

    if (to.meta.permission && active && !active.permissions.includes(to.meta.permission)) {
      return { name: 'overview' };
    }

    return true;
  });

  return router;
}
