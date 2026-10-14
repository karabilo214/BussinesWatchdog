import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import CoverageList from '@/components/CoverageList.vue';
import { localization, setLocale } from '@/i18n';
import { makeStore } from './fixtures';

const global = { plugins: [localization] };

beforeEach(() => setLocale('ru'));

describe('checkout observation coverage (ADR 0057)', () => {
  it('is shown for Shopware stores and hidden where it is not available', () => {
    const woo = makeStore();
    woo.coverage.checkout_script = { state: 'not_available', last_report_at: null };
    expect(mount(CoverageList, { props: { coverage: woo.coverage }, global }).find('[data-coverage="checkout_script"]').exists()).toBe(false);

    const shopware = makeStore({ platform: 'shopware' });
    shopware.coverage.checkout_script = { state: 'check_does_not_reach_payment', last_report_at: null };
    const row = mount(CoverageList, { props: { coverage: shopware.coverage }, global }).get('[data-coverage="checkout_script"]');
    expect(row.text()).toContain('Наблюдение за оформлением');
    expect(row.text()).toContain('нужна проверка с тестовым покупателем или гостем');

    const woo2 = makeStore();
    woo2.coverage.checkout_script = { state: 'needs_plugin', last_report_at: null };
    expect(mount(CoverageList, { props: { coverage: woo2.coverage }, global }).get('[data-coverage="checkout_script"]').text()).toContain('только при подключении через плагин');

    shopware.coverage.checkout_script = { state: 'observing', last_report_at: '2026-10-14T10:00:00Z' };
    expect(mount(CoverageList, { props: { coverage: shopware.coverage }, global }).get('[data-coverage="checkout_script"]').text()).toContain('работает');
  });
});
