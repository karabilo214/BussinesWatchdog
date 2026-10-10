import { readdirSync, readFileSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api/notifications';
import type { NotificationChannel, NotificationPreferences } from '@/api/types';
import ChannelCard from '@/components/notifications/ChannelCard.vue';
import PreferencesForm from '@/components/notifications/PreferencesForm.vue';
import { DELIVERY_TONES } from '@/components/notifications/tones';
import en from '@/i18n/locales/en.json';
import { localization, setLocale } from '@/i18n';
import { makeStore } from './fixtures';

vi.mock('@/api/notifications', () => ({
  verifyChannel: vi.fn(),
  resendChannelCode: vi.fn(),
  updateChannel: vi.fn(),
  testChannel: vi.fn(),
}));

const mocked = vi.mocked(api);
const global = { plugins: [localization], stubs: { RouterLink: RouterLinkStub } };

const preferences: NotificationPreferences = {
  min_severity: 'warning',
  locale: 'ru',
  timezone: 'Europe/Berlin',
  quiet_hours: { start: '22:00', end: '07:00' },
  critical_bypasses_quiet_hours: false,
  notify_recovery: true,
  store_ids: null,
};

function channel(overrides: Partial<NotificationChannel> = {}): NotificationChannel {
  return {
    id: 'ch-1', kind: 'email', label: 'Дежурный', destination_masked: 'a***@example.test', enabled: true,
    verified_at: '2026-10-01T00:00:00Z', preferences: {}, effective_preferences: preferences, health: {}, created_at: '2026-10-01T00:00:00Z',
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('notification contract', () => {
  it('every delivery state has a colour and a label; only "sent" is green', () => {
    const contract = readFileSync(resolve(__dirname, '../../../../contracts/openapi.yaml'), 'utf8');
    const schema = contract.slice(contract.indexOf('\n    NotificationDelivery:'));
    const states = /status: \{enum: \[([^\]]+)\]/.exec(schema)![1]!.split(',').map((value) => value.trim());

    expect(new Set(states)).toEqual(new Set(Object.keys(DELIVERY_TONES)));

    for (const state of states) {
      expect((en.notifications.log.state as Record<string, string>)[state], state).toBeTruthy();
      expect(DELIVERY_TONES[state as keyof typeof DELIVERY_TONES] === 'ok').toBe(state === 'sent');
    }
  });

  it('every delivery error and channel rejection code is explained', () => {
    const dir = resolve(__dirname, '../../../backend/app/Support/Notifications');
    const files: string[] = [];
    const walk = (path: string): void => {
      for (const name of readdirSync(path)) {
        const full = resolve(path, name);
        if (statSync(full).isDirectory()) walk(full);
        else if (name.endsWith('.php')) files.push(full);
      }
    };
    walk(dir);
    const source = files.map((file) => readFileSync(file, 'utf8')).join('\n');
    const deliveryCodes = new Set([...source.matchAll(/const (?:ERROR|REASON)_[A-Z_]+ = '([a-z_]+)'/g), ...source.matchAll(/'(email_transport_[a-z_]+)'/g)].map((match) => match[1]!));
    const rejections = new Set([...source.matchAll(/NotificationChannelRejected\('([a-z_]+)'\)/g)].map((match) => match[1]!));
    const controller = readFileSync(resolve(__dirname, '../../../backend/app/Http/Controllers/Api/V1/Notifications/NotificationChannelController.php'), 'utf8');

    for (const match of controller.matchAll(/NotificationChannelRejected\('([a-z_]+)'\)/g)) rejections.add(match[1]!);

    expect(deliveryCodes.size).toBeGreaterThan(5);

    for (const code of deliveryCodes) {
      expect((en.notifications.log.error as Record<string, string>)[code], code).toBeTruthy();
    }

    for (const code of rejections) {
      expect((en.errors as Record<string, string>)[code], code).toBeTruthy();
    }
  });
});

describe('ChannelCard', () => {
  it('confirms an unverified address with the mailed code and can ask for a new one', async () => {
    mocked.verifyChannel.mockResolvedValue(channel());
    mocked.resendChannelCode.mockResolvedValue();
    const wrapper = mount(ChannelCard, { props: { channel: channel({ verified_at: null, enabled: false }), stores: [], isOwner: true }, global });

    expect(wrapper.get('[data-tone]').attributes('data-tone')).toBe('warn');
    expect(wrapper.text()).not.toContain('Отправить тестовое письмо');

    await wrapper.findAll('button').find((button) => button.text() === 'Прислать новый код')!.trigger('click');
    await flushPromises();
    expect(mocked.resendChannelCode).toHaveBeenCalledWith('ch-1');
    expect(wrapper.text()).toContain('Новый код отправлен.');

    await wrapper.get('#code-ch-1').setValue('123456');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocked.verifyChannel).toHaveBeenCalledWith('ch-1', '123456');
    expect(wrapper.emitted('changed')).toHaveLength(1);
  });

  it('shows a failing address in red with the reason to act', () => {
    const wrapper = mount(ChannelCard, {
      props: { channel: channel({ health: { status: 'failing', last_dead_letter_at: '2026-10-09T10:00:00Z', last_error_code: 'email_transport_error' } }), stores: [], isOwner: true },
      global,
    });

    expect(wrapper.get('[data-tone]').attributes('data-tone')).toBe('crit');
    expect(wrapper.get('[data-health]').text()).toContain('не удаётся доставить');
  });

  it('sends a test email and reports it', async () => {
    mocked.testChannel.mockResolvedValue({} as never);
    const wrapper = mount(ChannelCard, { props: { channel: channel(), stores: [], isOwner: true }, global });

    await wrapper.findAll('button').find((button) => button.text() === 'Отправить тестовое письмо')!.trigger('click');
    await flushPromises();

    expect(mocked.testChannel).toHaveBeenCalledWith('ch-1');
    expect(wrapper.emitted('tested')).toHaveLength(1);
  });
});

describe('PreferencesForm', () => {
  it('never sends the critical bypass for a non-owner and keeps store choices', async () => {
    const stores = [makeStore({ id: 's-1', name: 'A' }), makeStore({ id: 's-2', name: 'B' })];
    const admin = mount(PreferencesForm, { props: { idPrefix: 'p', preferences, stores, isOwner: false }, global });

    expect(admin.vm.value()).not.toHaveProperty('critical_bypasses_quiet_hours');
    expect(admin.vm.value()).toMatchObject({ quiet_hours: { start: '22:00', end: '07:00' }, store_ids: null });

    const all = admin.findAll('input[type="checkbox"]').find((input) => (input.element.parentElement?.textContent ?? '').includes('все магазины'))!;
    await all.setValue(false);
    expect(admin.vm.valid()).toBe(false);
    await admin.find('input[value="s-2"]').setValue(true);
    expect(admin.vm.value().store_ids).toEqual(['s-2']);

    const owner = mount(PreferencesForm, { props: { idPrefix: 'o', preferences, stores, isOwner: true }, global });
    expect(owner.vm.value()).toHaveProperty('critical_bypasses_quiet_hours', false);
  });
});
