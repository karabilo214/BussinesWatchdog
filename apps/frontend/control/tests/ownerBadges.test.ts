import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import { ownerBadges, useOwnerBadges } from '@/composables/useOwnerBadges';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

vi.mock('@/api', () => ({ fetchOwnerAttention: vi.fn() }));
const mocked = vi.mocked(api);

describe('admin menu badges', () => {
  it('shows nothing when nothing waits', () => {
    expect(ownerBadges({ contacts_new: 0, service_state: 'ok', refunds_requested: 0, subscriptions_past_due: 0, support_requests_pending: 0 })).toEqual({});
    expect(ownerBadges({})).toEqual({});
  });

  it('counts all actionable sections and colours the service by its state', () => {
    const badges = ownerBadges({ contacts_new: 3, service_state: 'crit', refunds_requested: 120, subscriptions_past_due: 2, support_requests_pending: 1 });
    expect(badges.contacts).toEqual({ text: '3', level: 'warn', hint: { key: 'nav_badge.contacts_new', n: 3 } });
    expect(badges.service).toMatchObject({ text: '!', level: 'crit' });
    expect(badges.payments).toMatchObject({ text: '99+', level: 'warn' });
    expect(badges.subscriptions).toEqual({ text: '2', level: 'warn', hint: { key: 'nav_badge.subscriptions_past_due', n: 2 } });
    expect(badges.tenants).toEqual({ text: '1', level: 'warn', hint: { key: 'nav_badge.support_requests_pending', n: 1 } });
    expect(ownerBadges({ service_state: 'warn' }).service?.level).toBe('warn');
  });

  it('has the hints in ru, en and de', () => {
    for (const locale of [ru, en, de]) {
      const texts = locale.nav_badge as Record<string, string>;
      expect(['contacts_new', 'service_crit', 'service_warn', 'refunds_requested', 'subscriptions_past_due', 'support_requests_pending'].filter((key) => !texts[key])).toEqual([]);
    }
  });

  it('uses one request and shows only sections the staff member may open', async () => {
    mocked.fetchOwnerAttention.mockResolvedValue({ contacts_new: 3, refunds_requested: 2, subscriptions_past_due: 1 });
    const sections = ref(['contacts']);
    const view = defineComponent({ setup() { const { badges } = useOwnerBadges(sections); return () => h('div', Object.keys(badges.value).join(',')); } });
    const wrapper = mount(view);
    await flushPromises();
    expect(mocked.fetchOwnerAttention).toHaveBeenCalledTimes(1);
    expect(wrapper.text()).toBe('contacts');

    sections.value = ['contacts', 'subscriptions'];
    await flushPromises();
    expect(mocked.fetchOwnerAttention).toHaveBeenCalledTimes(2);
    expect(wrapper.text()).toBe('contacts,subscriptions');
    wrapper.unmount();
  });
});
