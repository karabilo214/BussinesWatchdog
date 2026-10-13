import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import * as maintenanceApi from '@/api/maintenance';
import { localization, setLocale } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';

vi.mock('@/api/maintenance', () => ({ getMaintenance: vi.fn() }));

async function mountLayout() {
  setLocale('ru');
  const router = createRouter({ history: createMemoryHistory('/app/'), routes: [{ path: '/:p(.*)*', name: 'overview', component: { template: '<div />' } }] });
  await router.push('/');
  const wrapper = mount(AppLayout, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
  await flushPromises();

  return wrapper;
}

describe('layout', () => {
  it('links the logo to the public site home in the current language', async () => {
    vi.mocked(maintenanceApi.getMaintenance).mockResolvedValue(null);
    expect((await mountLayout()).get('[data-logo]').attributes('href')).toBe('/ru');
    setLocale('de');
    const router = createRouter({ history: createMemoryHistory('/app/'), routes: [{ path: '/:p(.*)*', name: 'overview', component: { template: '<div />' } }] });
    await router.push('/');
    const wrapper = mount(AppLayout, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();
    expect(wrapper.get('[data-logo]').attributes('href')).toBe('/de');
  });
});

describe('maintenance banner', () => {
  it('shows planned and running maintenance from the service owner', async () => {
    vi.mocked(maintenanceApi.getMaintenance).mockResolvedValue({ starts_at: '2026-10-21T22:00:00Z', ends_at: '2026-10-22T02:00:00Z', message: 'Проверки приостановлены.', active: false });
    const planned = await mountLayout();
    expect(planned.get('[data-maintenance]').text()).toContain('Плановые работы');
    expect(planned.get('[data-maintenance]').text()).toContain('Проверки приостановлены.');

    vi.mocked(maintenanceApi.getMaintenance).mockResolvedValue({ starts_at: '2026-10-20T09:00:00Z', ends_at: '2026-10-20T12:00:00Z', message: 'Идут работы.', active: true });
    expect((await mountLayout()).get('[data-maintenance]').text()).toContain('Идут плановые работы до');
  });

  it('shows nothing when nothing is planned or the request fails', async () => {
    vi.mocked(maintenanceApi.getMaintenance).mockResolvedValue(null);
    expect((await mountLayout()).find('[data-maintenance]').exists()).toBe(false);
    vi.mocked(maintenanceApi.getMaintenance).mockRejectedValue(new Error('offline'));
    expect((await mountLayout()).find('[data-maintenance]').exists()).toBe(false);
  });
});
