import { createRouter, createWebHistory, type Router } from 'vue-router';
import { useStaff } from '@/composables/useStaff';

declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean;
  }
}

export const OWNER_SECTIONS = ['overview', 'tenants', 'subscriptions', 'payments', 'plans', 'site', 'blocks', 'contacts', 'service', 'staff', 'audit', 'settings'] as const;
export type OwnerSection = (typeof OWNER_SECTIONS)[number];

export function buildRouter(): Router {
  const router = createRouter({
    history: createWebHistory('/'),
    routes: [
      { path: '/', redirect: { name: 'owner-overview' } },
      { path: '/owner/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { public: true } },
      { path: '/owner', name: 'owner-overview', component: () => import('@/views/OverviewView.vue') },
      { path: '/owner/service', name: 'owner-service', component: () => import('@/views/ServiceView.vue') },
      { path: '/owner/staff', name: 'owner-staff', component: () => import('@/views/StaffView.vue') },
      { path: '/owner/staff-invitation', name: 'staff-invitation', component: () => import('@/views/StaffInvitationView.vue'), meta: { public: true } },
      { path: '/owner/audit', name: 'owner-audit', component: () => import('@/views/AuditView.vue') },
      { path: '/owner/settings', name: 'owner-settings', component: () => import('@/views/SettingsView.vue') },
      { path: '/owner/tenants', name: 'owner-tenants', component: () => import('@/views/TenantsView.vue') },
      { path: '/owner/tenants/:id', name: 'owner-tenant', component: () => import('@/views/TenantView.vue'), props: true },
      { path: '/owner/tenants/:id/stores/:storeId', name: 'owner-tenant-store', component: () => import('@/views/TenantStoreView.vue'), props: true },
      { path: '/owner/subscriptions', name: 'owner-subscriptions', component: () => import('@/views/SubscriptionsView.vue') },
      { path: '/owner/payments', name: 'owner-payments', component: () => import('@/views/PaymentsView.vue') },
      { path: '/owner/site', name: 'owner-site', component: () => import('@/views/PagesView.vue') },
      { path: '/owner/site/pages/:id', name: 'owner-page', component: () => import('@/views/PageView.vue'), props: true },
      { path: '/owner/blocks', name: 'owner-blocks', component: () => import('@/views/BlocksView.vue') },
      { path: '/owner/blocks/:id', name: 'owner-block', component: () => import('@/views/BlockView.vue'), props: true },
      { path: '/owner/contacts', name: 'owner-contacts', component: () => import('@/views/ContactsView.vue') },
      { path: '/owner/contact-requests/:id', name: 'owner-contact', component: () => import('@/views/ContactView.vue'), props: true },
      { path: '/owner/plans', name: 'owner-plans', component: () => import('@/views/PlansView.vue') },
      { path: '/owner/discounts', name: 'owner-discounts', component: () => import('@/views/DiscountsView.vue') },
      { path: '/owner/discounts/history', name: 'owner-discount-history', component: () => import('@/views/DiscountHistoryView.vue') },
      { path: '/owner/plans/versions/:id', name: 'owner-plan-version', component: () => import('@/views/PlanVersionView.vue'), props: true },
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
