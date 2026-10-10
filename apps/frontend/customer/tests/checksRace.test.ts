import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import * as checks from '@/api/checks';
import * as stores from '@/api/stores';
import type { CheckScenario } from '@/api/types';
import { localization, setLocale } from '@/i18n';
import ChecksView from '@/views/ChecksView.vue';
import { makeStore } from './fixtures';

vi.mock('@/api/stores', () => ({ listStores: vi.fn(), getStore: vi.fn(), updateStore: vi.fn() }));
vi.mock('@/api/checks', () => ({ listScenarios: vi.fn(), listRuns: vi.fn(), createScenario: vi.fn(), updateScenario: vi.fn(), startRun: vi.fn() }));

describe('ChecksView', () => {
  it('keeps the scenario of the selected store when an older response arrives later', async () => {
    setLocale('ru');
    const first = makeStore({ id: 's-1', name: 'First' });
    const second = makeStore({ id: 's-2', name: 'Second', verified_at: '2026-10-01T00:00:00Z', status: 'active', browser_enabled: true });
    let releaseFirst: (value: CheckScenario[]) => void = () => undefined;
    vi.mocked(stores.listStores).mockResolvedValue([first, second]);
    vi.mocked(checks.listRuns).mockResolvedValue({ data: [], next_cursor: null });
    vi.mocked(checks.listScenarios).mockImplementation((storeId: string) =>
      storeId === 's-1'
        ? new Promise((resolve) => {
            releaseFirst = resolve;
          })
        : Promise.resolve([
            {
              id: 'sc-2', store_id: 's-2', name: 'Payment form', mode: 'payment_form', version: 1, enabled: false, adapter_version: 'a',
              product_external_id: null, product_url: 'https://second.example/p/', cart_url: null, checkout_url: null, extra_allowed_origins: [],
              synthetic_location: null, interval_seconds: 900, next_due_at: null, supported_steps: [], untested_components: [],
            },
          ]),
    );
    const router = createRouter({ history: createMemoryHistory('/app/'), routes: [{ path: '/checks', name: 'checks', component: ChecksView }, { path: '/:p(.*)*', name: 'store', component: ChecksView }] });
    await router.push('/checks');
    const wrapper = mount(ChecksView, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    await router.replace({ query: { store: 's-2' } });
    await flushPromises();
    releaseFirst([]);
    await flushPromises();

    expect(wrapper.text()).toContain('https://second.example/p/');
    expect(wrapper.find('#scenario-product').exists()).toBe(false);
  });
});
