import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import type { Store } from '@/api/types';
import StoreSwitcher from '@/components/StoreSwitcher.vue';
import { useStoreChoice } from '@/composables/useStoreChoice';
import { localization, setLocale } from '@/i18n';
import { makeStore } from './fixtures';

const empty = defineComponent({ render: () => h('div') });

async function page(stores: Store[], path = '/checks'): Promise<{ router: Router; storeId: () => string }> {
  const router = createRouter({ history: createMemoryHistory('/app/'), routes: [{ path: '/checks', name: 'checks', component: empty }, { path: '/reconciliation', name: 'reconciliation', component: empty }] });
  await router.push(path);
  let read = () => '';
  mount(defineComponent({ setup: () => { const { storeId } = useStoreChoice(ref(stores)); read = () => storeId.value; return () => h('div'); } }), { global: { plugins: [router] } });
  await flushPromises();

  return { router, storeId: () => read() };
}

const a = makeStore({ id: 's-a', name: 'Kaffee', platform: 'woocommerce' });
const b = makeStore({ id: 's-b', name: 'Tee-Laden', platform: 'shopware' });

describe('store choice on per-store pages', () => {
  beforeEach(() => window.localStorage.clear());
  afterEach(() => window.localStorage.clear());

  it('never silently picks the first of several stores', async () => {
    const { storeId, router } = await page([a, b]);

    expect(storeId()).toBe('');
    expect(router.currentRoute.value.query.store).toBeUndefined();
  });

  it('takes the only store and puts it in the address', async () => {
    const { storeId, router } = await page([a]);

    expect(storeId()).toBe('s-a');
    expect(router.currentRoute.value.query.store).toBe('s-a');
  });

  it('carries the store chosen on one page to the next, and ignores an unknown one', async () => {
    const first = await page([a, b], '/reconciliation?store=s-b&status=open');
    expect(first.storeId()).toBe('s-b');

    const next = await page([a, b], '/checks');
    expect(next.storeId()).toBe('s-b');
    expect(next.router.currentRoute.value.query.store).toBe('s-b');

    const unknown = await page([a, b], '/checks?store=gone');
    expect(unknown.storeId()).toBe('s-b');
  });
});

describe('StoreSwitcher', () => {
  it('shows stores as buttons with their platform, marks the chosen one and asks when none is chosen', async () => {
    setLocale('ru');
    const wrapper = mount(StoreSwitcher, { props: { stores: [a, b], modelValue: '' }, global: { plugins: [localization] } });

    expect(wrapper.get('[data-store-required]').text()).toContain('Выберите магазин');
    await wrapper.get('[data-store="s-b"]').trigger('click');
    expect(wrapper.emitted('select')).toEqual([['s-b']]);
    expect(wrapper.get('[data-store="s-a"] img').attributes('src')).toContain('woocommerce-logo.svg');

    await wrapper.setProps({ modelValue: 's-a' });
    expect(wrapper.get('[data-store="s-a"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.find('[data-store-required]').exists()).toBe(false);
  });

  it('turns more than five stores into a list', async () => {
    const many = Array.from({ length: 6 }, (_, index) => makeStore({ id: `s-${index}`, name: `Shop ${index}` }));
    const wrapper = mount(StoreSwitcher, { props: { stores: many, modelValue: 's-2' }, global: { plugins: [localization] } });

    expect(wrapper.findAll('[data-store]')).toHaveLength(0);
    await wrapper.get('select').setValue('s-4');
    expect(wrapper.emitted('select')).toEqual([['s-4']]);
  });
});
