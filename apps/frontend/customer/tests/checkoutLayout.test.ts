import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as storesApi from '@/api/stores';
import type { Store } from '@/api/types';
import CheckoutLayoutSettings from '@/components/CheckoutLayoutSettings.vue';
import { localization, setLocale } from '@/i18n';

vi.mock('@/api/stores', () => ({ saveCheckoutLayout: vi.fn() }));

const defaults = { confirm_path: '/checkout/confirm', finish_path: '/checkout/finish', order_form: '#confirmOrderForm', submit_button: '#confirmFormSubmit', payment_method: '.payment-method-input', error_notice: '.alert-danger' };

function store(custom: Record<string, string> = {}, platform = 'shopware'): Store {
  return { id: 's-1', platform, checkout_layout: platform === 'shopware' ? { custom, defaults } : null } as unknown as Store;
}

describe('checkout layout settings', () => {
  it('shows the Shopware defaults as placeholders and saves only the filled fields', async () => {
    setLocale('ru');
    const saved = store({ submit_button: 'button.buy' });
    vi.mocked(storesApi.saveCheckoutLayout).mockResolvedValue(saved);
    const wrapper = mount(CheckoutLayoutSettings, { props: { store: store(), canManage: true }, global: { plugins: [localization] } });

    expect(wrapper.text()).toContain('Страницы и элементы оформления заказа');
    expect(wrapper.get('[data-layout-field="submit_button"]').attributes('placeholder')).toBe('#confirmFormSubmit');
    await wrapper.get('[data-layout-field="submit_button"]').setValue(' button.buy ');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(storesApi.saveCheckoutLayout).toHaveBeenCalledWith('s-1', { submit_button: 'button.buy' });
    expect(wrapper.emitted('updated')?.[0]).toEqual([saved]);
  });

  it('explains a rejected field and is hidden for other platforms', async () => {
    vi.mocked(storesApi.saveCheckoutLayout).mockRejectedValue(new ApiError(422, 'validation_failed', { confirm_path: ['checkout_layout_path_invalid'] }));
    const wrapper = mount(CheckoutLayoutSettings, { props: { store: store(), canManage: true }, global: { plugins: [localization] } });
    await wrapper.get('[data-layout-field="confirm_path"]').setValue('kasse');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Путь должен начинаться с «/»');

    const other = mount(CheckoutLayoutSettings, { props: { store: store({}, 'woocommerce'), canManage: true }, global: { plugins: [localization] } });
    expect(other.find('[data-panel="checkout-layout"]').exists()).toBe(false);
  });
});
